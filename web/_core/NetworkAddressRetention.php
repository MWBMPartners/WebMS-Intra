<?php
// Path: _core/NetworkAddressRetention.php
/**
 * -----------------------------------------------------------------------------
 * How long a network address is kept, in the places with no other limit 🌐⏳
 * -----------------------------------------------------------------------------
 * The ONE place that answers "after how many days is a network (IP) address
 * emptied from these records, or is it kept for ever?", the ONE writer of the
 * setting that decides it, `privacy.networkAddressDays`, and the ONE list of
 * the places the nightly clear-out empties (`PLACES`). Do not read or write
 * the setting anywhere else.
 *
 * WHY IT EXISTS (#1019; the owner's decision D-178, 12 November 2026). The
 * portal keeps addresses for security, misuse and support, and handles them as
 * personal data: every place that keeps one has a SET keeping period. Nine
 * places had none — password-reset requests, devices trusted for two-step
 * sign-in, prayer requests, an access key's last-used address, live-chat
 * messages, answers to public forms, recording plays, and the two change
 * histories (`tblAuditTrail`, `tblAssetAudit`). The owner chose ONE setting
 * for all of them, a year by default to match the activity log, with the
 * activity log's rules (D-117 to D-119): `RetentionPeriod` holds those rules
 * and this class names the setting, the words and the places.
 *
 * WHAT THE CLEAR-OUT DOES: it EMPTIES the address and KEEPS every record. A
 * change-history entry is kept for ever (D-116) — only its address goes. A
 * prayer request, a chat message, a form answer stays exactly as it was.
 *
 * WHAT IT DOES NOT COVER, on purpose: places that already have a period
 * (the activity log, error records, scan records, found-equipment reports,
 * check-ins at the door, rate limits), Gift Aid declarations (emptied at the
 * organisation's keep-until date for giving records, and by an erasure), and
 * the record of privacy-notice choices (`tblConsentLog`), which follows the
 * privacy data-retention setting under D-166 once #934 builds its clear-out.
 *
 * THE DATE EACH PLACE IS MEASURED FROM is the moment the address was written:
 * the row's creation for most, the play for a recording play, and the last
 * use for an access key (its address is REPLACED on every use, with
 * `lastUsedAt`, in `ApiKey`, so the address is as old as that moment).
 *
 * WHOSE PERIOD: a place with an organisation follows that organisation's
 * figure, held to the installation's. A password reset and a trusted device
 * belong to an ACCOUNT, which is installation-wide, not to any organisation,
 * so they follow the installation's figure — as do change-history entries
 * with no organisation.
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/1019
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

// 📦 The rules, loaded without asking the autoloader first: the retention
//    sweep's self-tests require this file by hand.
if (class_exists(RetentionPeriod::class, false) === false) {
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'RetentionPeriod.php';
}

final class NetworkAddressRetention extends RetentionPeriod
{
    /** The setting, dotted. Seeded installation-wide (365, migration 295); an organisation may have its own row. */
    public const KEY = 'privacy.networkAddressDays';

    /** A year, to match the activity log (the owner, #1019). */
    public const DEFAULT_DAYS = 365;

    /** What the general settings editor says when it refuses the key. */
    public const LOCKED_MESSAGE = 'How long network addresses are kept is set on Admin, Maintenance, '
        . 'Retention, where an organisation\'s figure is checked against the installation\'s and a '
        . 'refusal is recorded. It is not changed here. Nothing was changed.';

    /**
     * 🗺️ Every place the nightly clear-out empties, and nothing else.
     *
     * `dated` is the column the address's age is measured from. `site` is the
     * column naming the organisation, or null where a row belongs to an
     * account rather than an organisation. `via` names a parent table to join
     * for the organisation, as [table, key column shared by both].
     *
     * Every name here is a literal in this file and never comes from a
     * request, which is what makes it safe to put into a statement; each is
     * backticked where it is used. `tools/audit-checks/check_network_address_periods.py`
     * reads this list: a column on it must have a catalogue sentence naming
     * this period, and a sentence naming the period must have its column here.
     *
     * @var list<array{table: string, column: string, dated: string, site: ?string, via: ?array{0: string, 1: string}}>
     */
    public const PLACES = [
        ['table' => 'tblPasswordResets',   'column' => 'createdIP',   'dated' => 'createdAt',  'site' => null,     'via' => null],
        ['table' => 'tblTrustedDevices',   'column' => 'createdIP',   'dated' => 'createdAt',  'site' => null,     'via' => null],
        ['table' => 'tblPrayerRequests',   'column' => 'submitterIP', 'dated' => 'createdAt',  'site' => 'siteID', 'via' => null],
        ['table' => 'tblApiKeys',          'column' => 'lastUsedIP',  'dated' => 'lastUsedAt', 'site' => 'siteID', 'via' => null],
        ['table' => 'tblLiveChatMessages', 'column' => 'senderIP',    'dated' => 'createdAt',  'site' => 'siteID', 'via' => null],
        ['table' => 'tblFormResponses',    'column' => 'submitterIP', 'dated' => 'createdAt',  'site' => 'siteID', 'via' => null],
        ['table' => 'tblRecordingPlay',    'column' => 'ipHash',      'dated' => 'playedAt',   'site' => 'siteID', 'via' => ['tblRecording', 'recordingID']],
        ['table' => 'tblAuditTrail',       'column' => 'ipAddress',   'dated' => 'createdAt',  'site' => 'siteID', 'via' => null],
        ['table' => 'tblAssetAudit',       'column' => 'ipHash',      'dated' => 'createdAt',  'site' => 'siteID', 'via' => null],
    ];

    /**
     * Whether the general settings editor must refuse this key — to change, to
     * add and to delete, for everybody. Changing it there skips the three-tier
     * refusal; deleting the installation's row moves every organisation that
     * follows it back to a year. Declared HERE, not on RetentionPeriod: see
     * that class's header.
     */
    public static function lockedInGeneralEditor(string $key): bool
    {
        return strcasecmp($key, self::KEY) === 0;
    }

    /** Network addresses' own words for RetentionPeriod. */
    protected static function words(): array
    {
        return [
            'period'       => 'network-address period',
            'never'        => 'never emptied',
            'neverChoice'  => 'Keep for ever',
            'notAdmin'     => 'Only an administrator of this organisation can change how long it keeps network addresses. Nothing was saved.',
            'neverRefused' => 'This installation does not allow "Keep for ever" for network addresses, so nothing was saved. '
                . 'Choose a number of days between 1 and %d. '
                . 'Ask whoever runs this installation if your organisation needs to keep them longer.',
            'installationNever' => 'Saved. This installation now sets no time limit on network addresses. Addresses on records '
                . 'that belong to no organisation, and on those of every organisation that has not chosen a number of '
                . 'days of its own, are kept for ever; an organisation that has chosen a number of days still has them '
                . 'emptied after it, and every organisation may choose a number of days or "Keep for ever" for itself.',
            'installationDays' => 'Saved. This installation now empties network addresses after %s, and no organisation may keep them longer.',
            'aboveWhat'    => 'for keeping network addresses on their own records',
            'aboveRule'    => 'The portal will not empty an organisation\'s addresses sooner than that organisation chose.',
            'siteCleared'  => 'Saved. This organisation now follows the installation\'s figure for network addresses.',
            'siteNever'    => 'Saved. This organisation\'s network addresses are now kept for ever.',
            'siteDays'     => 'Saved. This organisation now empties network addresses after %s.',
        ];
    }

    /**
     * 📄 The privacy pages' sentence about this period, as plain text (the
     *    page escapes it). The catalogue's sentences for the places in
     *    `PLACES` say "the period given above for network addresses"; this is
     *    that period, for THIS organisation, from the reader the clear-out
     *    uses. Each state's words are spelled out WHOLE (#694).
     *
     * @param array{state: string, days: int} $facts From memberFactsForSite().
     */
    public static function privacyPageWords(array $facts): string
    {
        $period = match ($facts['state']) {
            self::MEMBER_DAYS => 'Where a place below says it follows the period for network addresses, the address is '
                . 'emptied by a nightly clear-out once it is ' . self::inWords($facts['days']) . ' old, if that '
                . 'scheduled job has been set up; the record itself is kept. This organisation\'s administrators set '
                . 'that period, within the limit set for the whole portal.',
            self::MEMBER_OWN_NEVER => 'Where a place below says it follows the period for network addresses, this '
                . 'organisation has chosen to keep the address for ever, so nothing empties it.',
            self::MEMBER_INSTALLATION_NEVER => 'Where a place below says it follows the period for network addresses, '
                . 'this portal keeps the address for ever, and this organisation has not chosen a shorter period, so '
                . 'nothing empties it.',
            default => 'Where a place below says it follows the period for network addresses, the address is emptied '
                . 'by a nightly clear-out after the period set for this organisation, if that scheduled job has been '
                . 'set up, unless that period is for ever. The period in force here could not be read just now.',
        };

        return $period . ' A record made at another organisation on this portal follows that organisation\'s period, '
            . 'and one that belongs to your account rather than to an organisation, or to no organisation, follows '
            . 'the period set for the whole portal.';
    }
}
