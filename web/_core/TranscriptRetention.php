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
 * The third tier is REACHABLE BUT NOT SETTABLE today: see "where it is set"
 * below. It is honoured because a row can exist without a page to write it.
 *
 * WHERE IT IS SET, AND WHERE IT IS NOT. The figure is installation-wide, set by
 * a global administrator on Admin, Transcription - which is where every other
 * transcription setting lives, because one API key pays for every organisation,
 * so that page writes `siteID IS NULL` rows only and refuses anybody who is not
 * a global administrator.
 *
 * THERE IS NO PER-ORGANISATION PAGE, and this class no longer implies one. A
 * first version carried `daysForThisOrganisation()`,
 * `installationAllowsForEver()` and `whyNotAllowed()` - three public methods NO
 * FILE UNDER `web/` CALLED, under a docblock saying "an organisation may tighten
 * it" that nothing could achieve, because `_apps/settings/save.php` refuses to
 * ADD a per-organisation row for a key the installation already holds. An
 * independent check found them. #687 records why that is worse than nothing: a
 * public method nobody calls, with an authoritative docblock, reads as a
 * feature. They are gone.
 *
 * `daysForSite()` STAYS, AND IS LIVE, because a per-organisation row can still
 * exist - written by hand, or restored from a backup - and the clear-out must
 * hold it to the installation's ceiling either way. A save-time cap alone is not
 * a cap (#668). If a per-organisation page is ever wanted, those readers come
 * back with it and with #668's two questions: that is #722, which records what
 * removing them gave up.
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

    /** 🏛️ The longest period the INSTALLATION permits. 0 means "for ever". */
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
        $ceiling = self::installationAllows();

        $own = App::settingForSite(self::KEY, $siteId);
        if ($own === null || trim((string) $own) === '') {
            return $ceiling;
        }

        $days = (int) $own;
        if ($days < 0) {
            // Meaningless: treated as "not chosen" rather than "for ever",
            // because a negative number is a mistake and not an intention.
            return $ceiling;
        }
        if ($days > self::HARD_MAX_DAYS) {
            $days = self::HARD_MAX_DAYS;
        }
        if ($days === 0) {
            return $ceiling === 0 ? 0 : $ceiling;
        }
        if ($ceiling === 0) {
            return $days;
        }

        return min($days, $ceiling);
    }
}
