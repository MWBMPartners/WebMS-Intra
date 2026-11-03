<?php
// Path: web/_core/TranscriptRetention.php
/**
 * -----------------------------------------------------------------------------
 * How long a recording's transcript is kept 🎙️ (#714)
 * -----------------------------------------------------------------------------
 * The ONE place that answers "how long may a transcript be kept here?". Do not
 * work it out anywhere else, and do not read the setting directly.
 *
 * -----------------------------------------------------------------------------
 * WHAT IS IN A TRANSCRIPT, AND WHY IT NEEDED A LIMIT
 * -----------------------------------------------------------------------------
 * `tblTranscript.fullText` and `.jsonSegments` are the VERBATIM machine
 * transcription of a recording's audio: whatever was spoken, including any name,
 * address or telephone number somebody read out. `tblTranscriptSearch.body` is
 * a byte-for-byte second copy, held so it can be searched quickly.
 *
 * `Portal\Core\Transcription` itself shows the sensitivity was understood: it
 * deliberately refuses to record a provider's error text, because "a message
 * from a provider can quote what was sent to it, which here is somebody
 * speaking." The transcript is that same material, in full.
 *
 * NOTHING EVER REMOVED IT. The only route was the `ON DELETE CASCADE` from
 * `tblRecording`, and an erasure NEVER deletes a recording: it only detaches
 * `uploadedByID` and `presenterID`. So the cascade never fired, no scheduled job
 * touched these tables, and a transcript was kept for ever.
 *
 * -----------------------------------------------------------------------------
 * WHY AN ERASURE REQUEST IS NOT THE ANSWER HERE
 * -----------------------------------------------------------------------------
 * Two reasons, and the owner weighed both (3 November 2026).
 *
 * NO COLUMN SAYS WHOSE WORDS THEY ARE. A transcript belongs to a recording, and
 * the only account columns on that recording are the person who uploaded it and
 * the person named as presenter. Somebody who merely SPOKE at the meeting is
 * nowhere on the row. So an erasure has nothing to match on, and searching the
 * text for a name was rejected for #707's reason: it would match a namesake and
 * miss a nickname.
 *
 * AND IT IS A RECORD OF A MEETING INVOLVING OTHER PEOPLE. Deleting it on one
 * participant's request would destroy what everybody else said, which is the
 * same reasoning that keeps a pastoral visit note. An instruction keyed on the
 * presenter would also have LOOKED like coverage while reaching only the
 * transcripts where the erased person happened to be presenting.
 *
 * So the limit is a time limit, and it is the whole answer rather than part of
 * one. The decision and its limits are recorded in the personal-data catalogue
 * beside the two tables.
 *
 * -----------------------------------------------------------------------------
 * THE ROWS ARE DELETED, NOT EMPTIED
 * -----------------------------------------------------------------------------
 * Unlike a webhook payload (#713), nothing needs the row once the words are
 * gone: a transcript with no text is not a record of anything. Deleting the
 * `tblTranscript` row takes its search copy with it through
 * `fk_ts_transcript … ON DELETE CASCADE`, so there is one statement and no way
 * for the mirror to outlive the original.
 *
 * THE RECORDING ITSELF IS UNTOUCHED. The audio stays, with its own date, title
 * and presenter. What goes is the written-out words. Somebody who wants them
 * again can have the recording transcribed again.
 *
 * -----------------------------------------------------------------------------
 * CAPPED BY THE INSTALLATION (#668)
 * -----------------------------------------------------------------------------
 * Three tiers, each able to tighten the one above and none to loosen it: a hard
 * maximum in code, the installation, then the organisation. ZERO MEANS "KEEP FOR
 * EVER", so zero is the LARGEST value, and the flag is passed to
 * `SettingLimit` rather than converted here — #668's trap.
 *
 * WHERE EACH TIER IS SET. The INSTALLATION's figure is set by a global
 * administrator on Admin, Transcription - which is where every other
 * transcription setting lives, because one API key pays for every organisation,
 * so that page writes `siteID IS NULL` rows only. An ORGANISATION's own,
 * shorter figure is set on Admin, Settings, Transcript retention
 * (`/admin/settings/transcription`) by its own administrator - that is #722,
 * and it needs its own page because `_apps/settings/save.php` refuses to ADD a
 * per-organisation row for a key the installation already holds.
 *
 * HOW THAT PAGE CAME TO EXIST IS WORTH KEEPING, because it is a lesson rather
 * than a history. A first version of this class carried
 * `daysForThisOrganisation()`, `installationAllowsForEver()` and
 * `whyNotAllowed()` - three public methods NO FILE UNDER `web/` CALLED, under a
 * docblock saying "an organisation may tighten it" that nothing could achieve.
 * An independent check found them, and #687 records why that is worse than
 * nothing: a public method nobody calls, with an authoritative docblock, reads
 * as a feature. They were removed and the gap was written down as #722 instead
 * of being left quiet. The page is now built, so two of them are back WITH
 * CALLERS. `daysForThisOrganisation()` is NOT back: the page knows which
 * organisation it is serving and asks `daysForSite()`, and a reader of the
 * merged `$SETTINGS` would be one more thing a scheduled job must remember not
 * to call (#683).
 *
 * THE REFUSAL IS WORDED IN ONE PLACE AND IT IS NOT HERE. A first version of
 * this package put `whyNotAllowed()` and `refusalWords()` back on this class
 * as well - and NOTHING UNDER `web/` CALLED EITHER. The page draws its box
 * from the two ceiling questions and `saveForSite()` asks
 * `SettingLimit::whyNotAllowedFor()` against the LIVE, locked row, which is
 * the only answer that may decide a save; a snapshot-based copy beside it
 * would have been a second rule that is sometimes wrong. That is #687's shape
 * for the second time in this class's short life, and the first time is written
 * out just above: a public method nobody calls, with an authoritative docblock,
 * reads as a feature. Count the callers before adding a method here.
 *
 * `daysForSite()` CAPS WHATEVER IT FINDS, page or no page, because a
 * per-organisation row can exist without one - written by hand, restored from a
 * backup, or stored before a save-time refusal existed. A save-time cap alone
 * is not a cap (#668, and #685 where exactly that gap was still live for
 * attendance retention).
 *
 * `WebhookRetention` (#713) is the same shape, deliberately not shared with it:
 * the two have different keys, different defaults, different reasons for
 * existing and different things to say about what they delete. One class with
 * a parameter would have to explain both in one docblock, and the next person
 * would have to read both to change either.
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/714
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

final class TranscriptRetention
{
    /** The setting, dotted. Seeded installation-wide; an organisation may tighten it. */
    public const KEY = 'transcription.retentionDays';

    /**
     * What somebody is told when they try to change this on the general
     * settings page. It names the pages that DO write it, because a refusal
     * that does not say where to go is a dead end — and it never says WHO may
     * change it, since the installation's figure and an organisation's are set
     * by different people and the refusal is the same either way.
     */
    public const LOCKED_MESSAGE = 'How long transcripts are kept is set on Admin, Settings, '
        . 'Transcript retention for this organisation, and on Admin, Transcription for the whole '
        . 'installation. It is not changed here, because those pages are where the figure is '
        . 'checked against what the installation allows and where a refusal is recorded.';

    /**
     * What an installation that has never chosen gets. A year: long enough that
     * a transcript is still there for the season or the series it belongs to,
     * and short enough that verbatim speech is not kept indefinitely.
     *
     * Deliberately NOT 0. "We kept every word anybody said for ever" must not
     * be what happens when nobody chooses.
     */
    public const DEFAULT_DAYS = 365;

    /** The most this may ever be, whatever anybody sets. Ten years. */
    public const HARD_MAX_DAYS = 3650;

    /**
     * 🏛️ The longest period the INSTALLATION permits, IN DAYS.
     *
     * ⚠️ IT NEVER ANSWERS 0, and this docblock used to say it meant "for ever".
     *    That was untrue and it mattered: `SettingLimit::installationAllows()`
     *    with the zero flag hands back the HARD MAXIMUM where the installation
     *    stored 0, because what a form's `max` and a comparison need is a
     *    number of days. So the branch in `run_transcript_sweep()` reading
     *    `$days <= 0` as "this organisation keeps them for ever, and the
     *    installation allows that" could never be reached — a comment stating
     *    a guarantee the code had forgotten to implement. Found while building
     *    #722, by asking what the method could return rather than reading what
     *    it said.
     *
     *    Ask `installationAllowsForEver()` for the other half. A page needs
     *    BOTH (#668): `min="0"` offers for-ever whatever the maximum says.
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
     * ♾️ Does the INSTALLATION allow "for ever" — a stored 0?
     *
     * The SECOND of #668's two questions, and it is back deliberately: the
     * first version of this class carried it with no page to ask it, which is
     * #687's "a public method nobody calls, with an authoritative docblock"
     * shape, so it was removed. #722 built the page, so it returns with a
     * caller — `/admin/settings/transcription`, which cannot decide whether to
     * offer 0 at all without it.
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
     * 🧹 The period for ONE NAMED organisation, for the scheduled clear-out.
     *    0 means "keep for ever".
     *
     * A scheduled job has no current organisation and `$SETTINGS` is merged once
     * for the whole run (#683), so it must ask by number — reading the merged
     * array in a job applies whichever organisation the job resolved to, to
     * every organisation's rows.
     *
     * The cap is applied HERE as well as at save time, because a row written by
     * hand, restored from a backup, or predating the save-time refusal is still
     * held to the installation's ceiling. A save-time cap alone is not a cap
     * (#668).
     */
    public static function daysForSite(int $siteId): int
    {
        $own  = App::settingForSite(self::KEY, $siteId);
        $text = $own === null ? '' : trim((string) $own);

        // ⚠️ "NOT CHOSEN" FOLLOWS THE INSTALLATION, NEVER THE BUILT-IN DEFAULT,
        //    and that is #668's FIRST trap: resolving a missing value to the
        //    default makes the default cap the installation, so an installation
        //    could lower this period but never raise one.
        //
        //    Three things count as "nothing usable": no row at EITHER level
        //    (`App::settingForSite()` falls back to the installation-wide row
        //    itself, so a null really does mean neither), an empty value, and a
        //    NEGATIVE number — meaningless, and read as "not chosen" rather
        //    than "for ever", because it is a mistake and not an intention.
        //    That is the OPPOSITE of `AnonymousCheckins`' reading of the same
        //    shape, which is right for its own setting and explained there.
        //
        //    It needs BOTH ceiling questions, and that is why: answering the
        //    DAYS ceiling alone gave an installation that had chosen "keep for
        //    ever" a period of 365 days for any organisation whose row was
        //    unusable, because `installationAllows()` never answers 0.
        if (preg_match('/^\d{1,18}$/', $text) !== 1) {
            return self::installationAllowsForEver() === true ? 0 : self::installationAllows();
        }

        // 🚧 The three-tier rule, in the ONE place it is written
        //    (`SettingLimit::smallerOfTwo()`). This used to be worked out here,
        //    and two of its branches could never run: `installationAllows()`
        //    never answers 0, so `$ceiling === 0` was dead and "for ever"
        //    could never survive even where the installation allowed it.
        return SettingLimit::cappedForSite(
            (int) $text,
            self::KEY,
            self::DEFAULT_DAYS,
            self::HARD_MAX_DAYS,
            true
        );
    }

    /**
     * 🏢 The period ONE organisation has chosen FOR ITSELF — null when it has
     *    chosen nothing.
     *
     * Read at that organisation's own level, never through
     * `App::settingForSite()` or the merged array, both of which fall back to
     * the installation's row. A box filled from either shows a number this
     * organisation never chose, and one press of Save makes it its own — #674,
     * and #677 where exactly that happened.
     *
     * @param \mysqli $db     An open connection.
     * @param int     $siteId The organisation.
     *
     * @return int|null The figure as stored, or null when there is no own row
     *                  (or it holds something that is not a whole number).
     */
    public static function ownDays(\mysqli $db, int $siteId): ?int
    {
        $stmt = $db->prepare('SELECT `settingValue` FROM `tblSettings` WHERE `settingKey` = ? AND `siteID` = ?');
        if ($stmt === false) {
            return null;
        }
        $key = self::KEY;
        $stmt->bind_param('si', $key, $siteId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row === null) {
            return null;
        }
        $text = trim((string) ($row['settingValue'] ?? ''));
        if (preg_match('/^\d{1,18}$/', $text) !== 1) {
            return null;
        }

        return (int) $text;
    }

    /**
     * 💾 Store, clear or refuse ONE organisation's own period (#722).
     *
     * THE WHOLE REASON THIS IS A METHOD AND NOT PAGE CODE is the lock. The
     * ceiling has to be re-read LIVE, with the installation's own settings row
     * locked, INSIDE the transaction that writes — and a page cannot be driven
     * by two simultaneous connections, so a page-level test cannot prove it.
     * `tools/retention-caps-selftest.php` drives this method on two real
     * connections instead. `GivingRetention::saveForSite()` is the precedent
     * and the measurement that forced it (#687): two ordinary overlapping web
     * requests stored fifty years under a ceiling of seven, 60 pairs out of 60,
     * because the gate compared against the request's settings snapshot.
     *
     * A LOCK OUTSIDE A TRANSACTION IS DECORATION (#697, where a `FOR UPDATE`
     * sat on a SELECT running before `begin_transaction()` and took no lock at
     * all while three documents said it did). So the transaction is opened
     * first, and every statement is inside it.
     *
     * `$days` of null means "follow the installation": the organisation's own
     * row is DELETED. The caller must have established that the field really
     * ARRIVED before calling with null — a disabled control is not submitted,
     * and reading an absent field as empty is how #677 cleared `site.url` for
     * every organisation.
     *
     * @param \mysqli  $db     An open connection, NOT already in a transaction.
     * @param int      $siteId The organisation.
     * @param int|null $days   The figure to store, or null to follow the installation.
     *
     * @return array{saved:bool,type:string,message:string,cleared:bool,refusal:string}
     */
    public static function saveForSite(\mysqli $db, int $siteId, ?int $days): array
    {
        // ✅ The hard maximum in code, before any transaction: a different
        //    thing from the installation's ceiling, and refused in different
        //    words.
        if ($days !== null && ($days < 0 || $days > self::HARD_MAX_DAYS)) {
            return [
                'saved'   => false,
                'type'    => 'danger',
                'message' => 'The number of days has to be a whole number between 0 and '
                    . self::HARD_MAX_DAYS . '. Nothing was saved.',
                'cleared' => false,
                'refusal' => 'above-hard-max',
            ];
        }

        $db->begin_transaction();
        try {
            // 🔒 THE CEILING AGAIN, LIVE AND LOCKED, before anything is
            //    written. One read, both facts, so the two can never disagree.
            $ceilingStored = self::installationCeilingLocked($db);

            if ($days !== null) {
                $reason = SettingLimit::whyNotAllowedFor(
                    $days,
                    $ceilingStored,
                    self::DEFAULT_DAYS,
                    self::HARD_MAX_DAYS,
                    true
                );
                if ($reason !== '') {
                    $db->rollback();
                    $allowed = SettingLimit::ceilingFrom(
                        $ceilingStored,
                        self::DEFAULT_DAYS,
                        self::HARD_MAX_DAYS,
                        true
                    );

                    return [
                        'saved'   => false,
                        'type'    => 'danger',
                        // The words are built from the LIVE ceiling, not from
                        // the request's snapshot, or a refusal could name a
                        // number that is no longer the limit.
                        'message' => SettingLimit::refusalWords($reason, $allowed, 'days'),
                        'cleared' => false,
                        'refusal' => $reason,
                    ];
                }
            }

            if ($days === null) {
                $del = $db->prepare('DELETE FROM `tblSettings` WHERE `settingKey` = ? AND `siteID` = ?');
                $key = self::KEY;
                $del->bind_param('si', $key, $siteId);
                $del->execute();
                $del->close();
            } else {
                // A real organisation number, so the unique key on
                // (settingKey, siteID) matches an existing row normally.
                $ins = $db->prepare(
                    'INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`, `updatedAt`) '
                    . 'VALUES (?, ?, ?, ?, 0, NOW()) '
                    . 'ON DUPLICATE KEY UPDATE `settingValue` = VALUES(`settingValue`), `updatedAt` = NOW()'
                );
                $key   = self::KEY;
                $value = (string) $days;
                $ins->bind_param('isss', $siteId, $key, $value, $value);
                $ins->execute();
                $ins->close();
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            error_log('[WebMS-Intra] TranscriptRetention: the transcript period of organisation #'
                . $siteId . ' could not be saved: ' . $e->getMessage());

            return [
                'saved'   => false,
                'type'    => 'danger',
                'message' => 'The period could not be saved because the database refused the change. Nothing was changed.',
                'cleared' => false,
                'refusal' => '',
            ];
        }

        if ($days === null) {
            return [
                'saved'   => true,
                'type'    => 'success',
                'message' => 'Your organisation now follows the period this installation sets.',
                'cleared' => true,
                'refusal' => '',
            ];
        }

        return [
            'saved'   => true,
            'type'    => 'success',
            'message' => $days === 0
                ? 'Saved. Your organisation keeps transcripts for ever.'
                : 'Saved. Your organisation keeps transcripts for ' . $days . ' day' . ($days === 1 ? '' : 's') . '.',
            'cleared' => false,
            'refusal' => '',
        ];
    }

    /**
     * 🔒 Is this settings key written only by its own page?
     *
     * The general settings editor asks this before changing, adding or
     * deleting a row, and refuses when it answers true — the same shape
     * `MailRoutes`, `Maintenance`, `GivingRetention`, `Site` and
     * `Capabilities` already use, and for the same reason.
     *
     * WHY THIS KEY, said properly, because the easy answer is not the reason.
     * An independent check pointed out that `transcription.retentionDays` had
     * no such refusal, and the reply "it is harmless because the reader caps
     * it" explains why the CONSEQUENCE is small — it does not explain why a
     * setting should be editable past its own gate. The gate is where the
     * three-tier rule lives (#668): an organisation's figure is refused above
     * the installation's, the refusal is RECORDED, and the person is told the
     * reason. The general editor skips all three, so a figure written there
     * is a number nobody judged and nobody recorded, which the clear-out then
     * silently holds down. **An unrecorded refusal is indistinguishable from
     * no refusal to whoever reads the log afterwards.**
     *
     * It covers the installation's own row AND an organisation's: both are
     * written by `/admin/settings/transcription` and
     * `/admin/transcription`, and neither is a privilege question — the same
     * global administrator may set either. What the refusal protects is the
     * single writer, not the permission.
     *
     * @param string $key The settings key being changed, added or deleted.
     *
     * @return bool
     */
    public static function lockedInGeneralEditor(string $key): bool
    {
        return strcasecmp($key, self::KEY) === 0;
    }

    /**
     * 🔒 The installation's stored period, read LIVE with its row locked.
     *
     * The caller must already be in a transaction: a lock outside one is
     * decoration (#697). Returns the text exactly as stored, or null when
     * there is no installation-wide row — the rule is applied by
     * `SettingLimit`, which is the one place that knows what an unusable value
     * means.
     *
     * IT IS PUBLIC FOR ONE REASON, said here so nobody mistakes it for a page's
     * API: `tools/retention-caps-selftest.php` starts a second process that has
     * to take THE SAME lock, through the portal's own method rather than a copy
     * of its SQL, or the test would prove a statement it wrote itself. Nothing
     * under `web/` calls it but `saveForSite()`.
     *
     * It locks ONE row, the installation's own, which is what serialises two
     * savers: anybody raising or lowering the ceiling takes the same lock, so
     * one waits for the other and sees what it did. The row is seeded by
     * migration 239, so it is there to be locked; on a database that has not
     * run it there is no ceiling to protect anyway.
     *
     * @param \mysqli $db An open connection, already inside a transaction.
     *
     * @return string|null
     */
    public static function installationCeilingLocked(\mysqli $db): ?string
    {
        $stmt = $db->prepare(
            'SELECT `settingValue` FROM `tblSettings` WHERE `settingKey` = ? AND `siteID` IS NULL FOR UPDATE'
        );
        if ($stmt === false) {
            throw new \RuntimeException('The transcript period ceiling could not be locked.');
        }
        $key = self::KEY;
        $stmt->bind_param('s', $key);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row === null ? null : (string) ($row['settingValue'] ?? '');
    }
}
