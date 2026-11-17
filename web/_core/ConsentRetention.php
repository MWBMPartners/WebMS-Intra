<?php
// Path: _core/ConsentRetention.php
/**
 * -----------------------------------------------------------------------------
 * How long a record of a privacy-notice or cookie choice is kept 🍪⏳
 * -----------------------------------------------------------------------------
 * The ONE place that answers "after how many days is a record of somebody's
 * choice about the privacy notice or cookies deleted, or is it kept for ever?",
 * the ONE writer of the setting that decides it, `privacy.dataRetentionDays`,
 * and the ONE statement of what "replaced" means for such a record
 * (`replacedFrom()`). Do not read or write the setting anywhere else (the
 * privacy page's policy fingerprint in `privacy/consent.php` reads it only to
 * hash it, which is not a use of the period).
 *
 * WHY IT EXISTS (#934; the owner's decisions D-163, D-166, D-210). The setting
 * was seeded at 730 days in March 2026 and read by nothing that mattered, so
 * the record of every cookie choice was kept for ever. D-163 kept it and
 * D-166 gave it its job: it governs how long a consent record is kept AFTER IT
 * IS REPLACED. It is NOT a general default for any other keeping period. The
 * rules are the activity log's (D-117 to D-119): the installation's figure caps
 * each organisation's, 0 means "keep for ever" and is the LARGEST value, a
 * figure above the cap is refused with the reason and never clamped.
 * `RetentionPeriod` holds those rules; this class names the setting and words.
 *
 * WHAT THE CLEAR-OUT DELETES: whole rows of `tblConsentLog` (the record is the
 * person's choice; the point of D-166 is that the record itself is not kept
 * for ever, not only its address) in two cases and no other:
 *   1. REPLACED: a later record exists for the same organisation, the same
 *      kind of choice and the same person (the account when the choice was made
 *      signed in, otherwise the same browser session), and that LATER record is
 *      itself older than the period. The period is measured from the replacing
 *      record, because that is the moment the older one stopped being the
 *      person's current answer.
 *   2. A VISITOR'S RECORD THE COOKIE HAS OUTLIVED (D-210): a choice made without
 *      signing in that nobody has replaced counts as replaced when the cookie it
 *      set runs out, which is `COOKIE_DAYS` (365) after it was made, because the
 *      browser then asks again. It is deleted `COOKIE_DAYS` plus the period
 *      after it was made.
 * THE LATEST RECORD OF A SIGNED-IN PERSON'S CHOICE IS NEVER DELETED by this: it
 * is the live consent. An erasure deletes a person's records at once, as before.
 *
 * 3. A MEMBER WHO HAS LEFT (D-211, #934 package 2h): every record of a person
 *      who left the organisation more than the period ago, the latest included.
 *      `Memberships::hasLeftSql()` is the ONE definition of "left" (the
 *      membership is off and dated, a global administrator never counts, and
 *      with one organisation the account must be off too). The record's own
 *      date must also be older than the period, so a record made after
 *      leaving still waits its own period: late, never early. A merged-away
 *      account's records are judged by the account the person now is
 *      (`AccountMerge::survivorOf()`), only while the old account is still as
 *      the merge closed it. A membership switched off with NO date has not
 *      started the clock. If the person returns they are asked again.
 *      Switching an ACCOUNT off (the Active tick) is not leaving (D-213), and
 *      Admin, Organisations, Users, Remove deletes the membership without a
 *      date (D-212): their records wait for rules 1, 2 or an erasure.
 *
 * WHOSE PERIOD: every record belongs to an organisation (`siteID` is not
 * empty), so each organisation's figure, held to the installation's, decides
 * its own records.
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/934
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

// 📦 The rules, loaded without asking the autoloader first (the sweep's
//    self-tests require this file by hand).
if (class_exists(RetentionPeriod::class, false) === false) {
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'RetentionPeriod.php';
}

final class ConsentRetention extends RetentionPeriod
{
    /** The setting, dotted. Seeded installation-wide (730, migration 048); an organisation may have its own row. */
    public const KEY = 'privacy.dataRetentionDays';

    /** The figure it has always been seeded with. */
    public const DEFAULT_DAYS = 730;

    /**
     * How long the cookie that remembers a cookie choice lasts. It is written
     * by `privacy/consent.php` (`365 * 86400`); the two must agree, and the
     * self-test reads that file to say so.
     */
    public const COOKIE_DAYS = 365;

    /** The most records one statement deletes, so a large backlog is cleared in pieces, never in one lock. */
    public const BATCH = 500;

    /** What the general settings editor says when it refuses the key. */
    public const LOCKED_MESSAGE = 'How long records of privacy-notice and cookie choices are kept is set on Admin, '
        . 'Maintenance, Retention, where an organisation\'s figure is checked against the installation\'s and a '
        . 'refusal is recorded. It is not changed here. Nothing was changed.';

    /**
     * Whether the general settings editor must refuse this key (change, add and
     * delete, for everybody). Declared HERE, not on RetentionPeriod: see that
     * class's header.
     */
    public static function lockedInGeneralEditor(string $key): bool
    {
        return strcasecmp($key, self::KEY) === 0;
    }

    /**
     * 🔎 The records one organisation's clear-out deletes, as the SQL text after
     *    `SELECT … FROM`: the ONE place "replaced" is defined, used by the count
     *    and by the selection so they cannot disagree. Bind it with `binds()`.
     *
     * HOW IT WORKS, and why it is not a "does a later row exist" probe per row:
     * a first version asked that with a correlated `EXISTS`, which read the
     * whole table again for every row (measured on 100,000 rows: more than
     * three minutes for ONE count). Instead each person's NEWEST record that is
     * already older than the period is found once, in two grouped lists, one
     * by account (`mu`) and one by browser-session fingerprint for visitors
     * (`ms`); a record is replaced when it is OLDER (a lower number — the
     * auto-number follows the order records were made in, even inside one
     * second) than that. So "a later record exists that is itself older than
     * the period" is exactly "the newest such record is later than this one".
     * A signed-in person's newest record is never below its own maximum, and a
     * record with an empty session names nobody (the visitor list leaves
     * those out), so neither is ever selected by this half.
     *
     * The other half is the visitor rule (D-210): no account, and older than
     * the cookie's life plus the period. Every name is a literal here.
     * Six placeholders: see binds().
     */
    public static function replacedFrom(int $leftUserCount = 0): string
    {
        // 🚪 D-211: in the PREVIEW, rows the member-has-left rule already counts
        //    are left out here, so a row due under both rules is counted ONCE.
        //    `IS NOT NULL` comes first on purpose: a visitor's row has no
        //    account, and `NULL IN (...)` is NULL, which would make the whole
        //    NOT(...) NULL and drop the visitor rule's rows from the count.
        $leftOut = '';
        if ($leftUserCount > 0) {
            $leftOut = ' AND NOT (`t`.`userID` IS NOT NULL AND `t`.`userID` IN ('
                . implode(',', array_fill(0, $leftUserCount, '?'))
                . ') AND `t`.`createdAt` < DATE_SUB(NOW(), INTERVAL ? DAY))';
        }

        return '`tblConsentLog` AS `t`'
            . ' LEFT JOIN (SELECT `siteID`, `consentType`, `userID`, MAX(`consentID`) AS `newest` FROM `tblConsentLog`'
            . '   WHERE `siteID` = ? AND `userID` IS NOT NULL AND `createdAt` < DATE_SUB(NOW(), INTERVAL ? DAY)'
            . '   GROUP BY `siteID`, `consentType`, `userID`) AS `mu`'
            . '   ON `mu`.`siteID` = `t`.`siteID` AND `mu`.`consentType` = `t`.`consentType` AND `mu`.`userID` = `t`.`userID`'
            . ' LEFT JOIN (SELECT `siteID`, `consentType`, `sessionID`, MAX(`consentID`) AS `newest` FROM `tblConsentLog`'
            . '   WHERE `siteID` = ? AND `userID` IS NULL AND `sessionID` IS NOT NULL AND `sessionID` <> \'\''
            . '   AND `createdAt` < DATE_SUB(NOW(), INTERVAL ? DAY)'
            . '   GROUP BY `siteID`, `consentType`, `sessionID`) AS `ms`'
            . '   ON `t`.`userID` IS NULL AND `ms`.`siteID` = `t`.`siteID` AND `ms`.`consentType` = `t`.`consentType`'
            . '   AND `ms`.`sessionID` = `t`.`sessionID`'
            . ' WHERE `t`.`siteID` = ? AND ('
            . '   `t`.`consentID` < `mu`.`newest`'
            . '   OR `t`.`consentID` < `ms`.`newest`'
            . '   OR (`t`.`userID` IS NULL AND `t`.`createdAt` < DATE_SUB(NOW(), INTERVAL ? DAY)))'
            . $leftOut;
    }

    /**
     * The values for replacedFrom()'s placeholders, in order: the organisation
     * and the period (account list), the organisation and the period (session
     * list), then the organisation and the period plus the cookie's life.
     *
     * With `$leftUserIds` (the same list whose COUNT was given to
     * `replacedFrom()`), the account numbers and then the period follow.
     *
     * @param list<int> $leftUserIds
     * @return list<int>
     */
    public static function binds(int $siteId, int $days, array $leftUserIds = []): array
    {
        $binds = [$siteId, $days, $siteId, $days, $siteId, $days + self::COOKIE_DAYS];
        if ($leftUserIds !== []) {
            foreach ($leftUserIds as $one) {
                $binds[] = $one;
            }
            $binds[] = $days;
        }
        return $binds;
    }

    /** Records-of-choices' own words for RetentionPeriod. */
    protected static function words(): array
    {
        return [
            'period'       => 'consent-record period',
            'never'        => 'never deleted',
            'neverChoice'  => 'Keep for ever',
            'notAdmin'     => 'Only an administrator of this organisation can change how long it keeps records of privacy-notice and cookie choices. Nothing was saved.',
            'neverRefused' => 'This installation does not allow "Keep for ever" for records of privacy-notice and cookie choices, so nothing was saved. '
                . 'Choose a number of days between 1 and %d. '
                . 'Ask whoever runs this installation if your organisation needs to keep them longer.',
            'installationNever' => 'Saved. This installation now sets no time limit on records of privacy-notice and cookie choices. Those of '
                . 'every organisation that has not chosen a number of days of its own are kept for ever; an organisation that has chosen '
                . 'a number of days still has them deleted after it, and every organisation may choose a number of days or "Keep for ever" for itself.',
            'installationDays' => 'Saved. This installation now deletes a replaced record of a privacy-notice or cookie choice after %s, and no organisation may keep them longer.',
            'aboveWhat'    => 'for keeping records of privacy-notice and cookie choices',
            'aboveRule'    => 'The portal will not delete an organisation\'s records sooner than that organisation chose.',
            'siteCleared'  => 'Saved. This organisation now follows the installation\'s figure for records of privacy-notice and cookie choices.',
            'siteNever'    => 'Saved. This organisation\'s records of privacy-notice and cookie choices are now kept for ever.',
            'siteDays'     => 'Saved. This organisation now deletes a replaced record of a privacy-notice or cookie choice after %s.',
        ];
    }

    /**
     * 📄 The privacy page's paragraph about this period, as plain text (the page
     *    escapes it). Each state's words are spelled out WHOLE (#694).
     *
     * @param array{state: string, days: int} $facts From memberFactsForSite().
     */
    public static function privacyPageWords(array $facts): string
    {
        $what = 'The portal keeps a record of each choice you make about the privacy notice and about cookies: '
            . 'which choice, when, and the browser session or account that made it. ';
        $cookie = ' A choice made without signing in counts as replaced when the cookie that remembers it runs out, '
            . self::COOKIE_DAYS . ' days after it was made, and is then deleted the same way.';
        $tail = ' Your latest choice while signed in is never deleted by this while you belong to the organisation. '
            . 'Asking for your account to be erased deletes all of your records at once. '
            . 'A record made at another organisation on this portal follows that organisation\'s period.';

        // 🚪 Said only where a period applies: with "keep for ever" nothing is deleted at all.
        $left = ' If your membership of this organisation is ended, your records here, the latest included, may be deleted once '
            . 'the period above has passed since it ended, unless you have been brought back by then. Your account being switched off '
            . 'for a while does not end your membership.';

        $core = match ($facts['state']) {
            self::MEMBER_DAYS => 'A record that a newer choice has replaced is deleted by a nightly clear-out once the newer one is '
                . self::inWords($facts['days']) . ' old, if that scheduled job has been set up. This organisation\'s administrators set '
                . 'that period, within the limit set for the whole portal.' . $cookie . $left,
            self::MEMBER_OWN_NEVER => 'This organisation has chosen to keep these records for ever, so nothing deletes them '
                . 'except an erasure.',
            self::MEMBER_INSTALLATION_NEVER => 'This portal keeps these records for ever, and this organisation has not chosen a '
                . 'shorter period, so nothing deletes them except an erasure.',
            default => 'A record that a newer choice has replaced is deleted by a nightly clear-out after the period set for this '
                . 'organisation, if that scheduled job has been set up, unless that period is for ever. The period in force here '
                . 'could not be read just now.' . $cookie . $left,
        };

        return $what . $core . $tail;
    }
}
