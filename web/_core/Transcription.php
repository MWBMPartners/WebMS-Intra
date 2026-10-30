<?php
// Path: _core/Transcription.php
/**
 * -----------------------------------------------------------------------------
 * Recording transcription + provider abstraction 🗒
 * -----------------------------------------------------------------------------
 * Auto-transcribe Recordings (#264) via a pluggable provider:
 *
 *   transcription.provider = openai | assemblyai | local
 *
 * HOW A TRANSCRIPT GETS MADE. An administrator presses "Transcribe" on a
 * recording's own page, which posts to `recordings/transcribe` and calls
 * `queue()`; that writes a `tblTranscript` row saying `queued`. An
 * administrator then presses "Process next" on `admin/transcription`, which
 * calls `processQueue()` and sends up to `batchSize` of this organisation's
 * waiting rows to the chosen company.
 *
 * NOTHING HAPPENS ON ITS OWN. There is no scheduled job that drains the queue,
 * so a recording queued and forgotten stays queued. That is deliberate for now
 * — the queue spends real money with an outside company and nobody should be
 * billed by a timer they did not know about — and it is tracked separately
 * rather than left to be discovered.
 *
 * THE DOCBLOCK HERE USED TO SAY "uploading a recording or hitting transcribe"
 * queued one. Neither was true. `recordings/upload.php` has never written a
 * transcript row, and until 29 October 2026 NO PAGE ANYWHERE posted to
 * `recordings/transcribe` — the handler and its address both existed and
 * nothing could reach them, so no transcript had ever been made and
 * `/recordings/search` searched an empty table. Said plainly because a comment
 * stating the guarantee the code forgot to implement is how a gap like that
 * survives being read.
 *
 * Results are persisted in two places: the canonical `tblTranscript.fullText
 * + jsonSegments`, and a denormalised mirror in `tblTranscriptSearch.body`
 * with a MySQL FULLTEXT index for `/recordings/search`.
 *
 * WHOSE MONEY, AND WHOSE DATA. One API key, stored installation-wide, pays for
 * every organisation on the installation, and the audio leaves the server for
 * a third party. So everything here is scoped to one organisation —
 * `processQueue()`, `monthSpendPence()`, `queueStatsFor()`, `recentFor()` — and
 * the monthly ceiling follows the owner's rule for a limit: the installation's
 * value caps the organisation's (`capFrom()`, #668).
 *
 * @package   Portal\Core
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/276
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

class Transcription
{
    // -------------------------------------------------------------------------
    // 💳 What may be spent, and who decides 🏢
    // -------------------------------------------------------------------------

    /** The installation-wide setting that holds the monthly ceiling, in pence. */
    public const CAP_KEY = 'transcription.monthCapPence';

    /** £50 a month — roughly 100 hours of Whisper at list price. */
    public const DEFAULT_CAP_PENCE = 5000;

    /**
     * £5,000 a month: the hard maximum in code, which no setting can raise.
     *
     * Three tiers, each unable to loosen the one above it: code, then the
     * installation, then the organisation. This is the first of them, and it
     * exists so that a typing slip in a settings box — an extra two noughts —
     * cannot authorise a bill nobody intended.
     */
    public const HARD_MAX_CAP_PENCE = 500000;

    /**
     * How long a job may sit in `processing` before it counts as abandoned.
     *
     * NOT a setting. This is not a limit an administrator sets on anybody; it
     * is how long we wait before deciding the host killed a request. Shared
     * hosting kills long requests without warning, and the longest provider
     * call this code can make is AssemblyAI's twenty-five-minute poll, so two
     * hours is comfortably past any real run.
     *
     * Measured in local time against `NOW()`, which is out by an hour on the
     * two nights a year the clocks change: a wedged row is then freed after
     * 60 or 180 minutes instead of 120. Harmless, and said rather than hidden.
     */
    public const STUCK_AFTER_MINUTES = 120;

    /**
     * 🏢 The monthly ceiling this organisation may actually spend, in pence.
     *
     * THE INSTALLATION CAPS THE ORGANISATION. One installation may serve many
     * organisations, and the administrator of the installation may be a
     * different person from the administrators of the organisations on it — a
     * hosting provider and its customers. Here that is not a nicety: ONE API
     * key, stored installation-wide, pays for every organisation's
     * transcription. So an organisation may hold itself to LESS than the
     * installation allows, and may never give itself more. The owner's rule,
     * 29 October 2026 (#668); `EventRecurrence::limitsFrom()` was the first
     * reader built this way and this is the same shape deliberately.
     *
     * `$installWide` is `InstallationSettings::layer()` — the installation's
     * own rows, with nothing laid over them. Pass it EMPTY rather than passing
     * the merged array: an empty layer means "the installation allows the
     * built-in default", which holds this organisation to £50 until start-up
     * can read the layer again. That is visibly less than an installation
     * which raised its cap asked for, and never more than it allowed. For a
     * boundary between two parties over somebody else's money, allowing too
     * little is recoverable and allowing too much is not — which is the case
     * `InstallationSettings::layer()` exists for.
     *
     * @param array<string,mixed> $settings    The MERGED settings ($SETTINGS).
     * @param array<string,mixed> $installWide The installation-wide layer alone.
     */
    public static function capFrom(array $settings, array $installWide): int
    {
        // 🏢 What the installation allows, resolved — so an installation that
        //    has chosen nothing still has a number.
        $allowed = self::installationAllowsPence($installWide);

        // 🏢 What this organisation asked for, UNRESOLVED: null means nothing
        //    anywhere has chosen a figure.
        //
        //    WHAT ACTUALLY STOPS THE DEFAULT CAPPING THE INSTALLATION, said
        //    precisely, because the first version of this comment overstated it
        //    and a planted fault proved the claim false. `$settings` is the
        //    MERGED array: when an organisation has chosen nothing, it already
        //    holds the INSTALLATION'S figure. So an installation that raises its
        //    ceiling to £200 gives min(20000, 20000) and the raise sticks. The
        //    merge is what carries that, not the test below — changing this line
        //    to resolve null to the default changes no answer in any reachable
        //    state, which was measured rather than reasoned about.
        //
        //    The test is still here, and is not decoration: it is the only thing
        //    that keeps the answer right if this is ever handed an UNMERGED
        //    array holding one organisation's own rows alone. Then "chose
        //    nothing" really is null, and resolving it to the built-in default
        //    would cap a £200 installation at £50 — the shape of the fault
        //    `EventRecurrence::limitsFrom()` shipped once and check H04 caught.
        //
        //    So the guard that section J really tests is the LAYER this reads:
        //    `$allowed` must come from the installation's own rows. Reading it
        //    from `$settings` instead would let an organisation's figure become
        //    its own ceiling, and min(x, x) is x. That is what J03 and J05 pin.
        $wanted = self::rawCapPence($settings);
        if ($wanted === null) {
            return $allowed;
        }

        return min($wanted, $allowed);
    }

    /**
     * 🏢 What the installation permits, for a page that wants to say so.
     *
     * @param array<string,mixed> $installWide
     */
    public static function installationAllowsPence(array $installWide): int
    {
        $raw = self::rawCapPence($installWide);

        return $raw === null ? self::DEFAULT_CAP_PENCE : $raw;
    }

    /**
     * The cap as stored, or null when nothing has been chosen at this layer.
     *
     * A value that is not a whole number, or is negative, counts as "not
     * chosen": a setting nobody can read must never be the thing that decides
     * what may be spent.
     *
     * A STORED ZERO IS ACCEPTED, and means "spend nothing" — a real choice for
     * an installation that wants transcription available but not in use. It is
     * NOT treated as "not chosen", because that would quietly turn a deliberate
     * zero into £50. `whyNotQueueable()` has its own reason for it, so nobody
     * is told a budget "has been used up" when none was ever set.
     *
     * The hard maximum is applied here, so no caller can forget it.
     *
     * @param array<string,mixed> $layer
     */
    private static function rawCapPence(array $layer): ?int
    {
        $value = $layer['transcription']['monthCapPence'] ?? null;
        if (is_string($value) === false && is_int($value) === false) {
            return null;
        }
        $text = trim((string) $value);
        if ($text === '' || preg_match('/^\d+$/', $text) !== 1) {
            return null;
        }

        return min((int) $text, self::HARD_MAX_CAP_PENCE);
    }

    // -------------------------------------------------------------------------
    // 🎚️ Whether a recording may be queued at all — ONE place
    // -------------------------------------------------------------------------

    /**
     * 🎚️ Why this recording may NOT be queued, or '' when it may be.
     *
     * THE ONE PLACE THAT DECIDES. The page uses it to draw the button and the
     * handler uses it to accept the request; a second test in either would
     * drift from the other, and the one in the page is only a courtesy — a
     * hidden button is not a boundary, because anything can be posted.
     *
     * It takes FACTS rather than a connection, for the same reason
     * `ExpenseRules::whyNotEditable()` does: the handler asks it twice, once
     * cheaply and once on facts read inside its own lock.
     *
     * The order of the tests is the order a person finds useful, not the order
     * they were written. "There is no audio here" comes before "nobody has set
     * up a provider", so somebody looking at a link-only recording is told the
     * thing that is true of THAT recording rather than sent to ask their
     * administrator for an API key that would not help.
     *
     * @param array{appIsOn:bool, hasMedia:bool, providerReady:bool, stillRunning:bool, capPence:int, spentPence:int} $facts
     */
    public static function whyNotQueueable(array $facts): string
    {
        if ($facts['appIsOn'] !== true) {
            return 'off';
        }
        if ($facts['hasMedia'] !== true) {
            return 'no-media';
        }
        if ($facts['providerReady'] !== true) {
            return 'no-provider';
        }
        if ($facts['stillRunning'] === true) {
            return 'busy';
        }
        // 💳 A budget of nothing is not a budget that ran out, and saying so
        //    would be untrue: an administrator told "this month's budget has
        //    been used up" when none was ever set goes looking for spending
        //    that never happened.
        if ($facts['capPence'] <= 0) {
            return 'no-budget';
        }
        if ($facts['spentPence'] >= $facts['capPence']) {
            return 'over-cap';
        }

        return '';
    }

    /**
     * 🎚️ A refusal in words a person can act on.
     *
     * Never the provider's own words: a fault's message can quote the request
     * and this project's rule is that it is neither logged nor shown.
     */
    public static function refusalWords(string $reason): string
    {
        return match ($reason) {
            'off'         => 'Transcription is switched off for this organisation. An administrator can turn it on under Admin, Apps.',
            'no-media'    => 'There is no audio or video file on this server for this recording, so there is nothing to transcribe. A recording that only links out to another website cannot be transcribed here.',
            'no-provider' => 'No transcription company has been set up yet. A global administrator can choose one and enter its key under Admin, Transcription.',
            'busy'        => 'This recording is being transcribed right now. Waiting for it to finish costs nothing; asking again would send the same audio twice and be charged twice.',
            'over-cap'    => 'This month\'s transcription budget has been used up. It starts again at the beginning of next month, or a global administrator can raise it under Admin, Transcription.',
            'no-budget'   => 'No transcription budget has been set, so nothing can be sent. A global administrator can set one under Admin, Transcription.',
            default       => 'This recording cannot be transcribed at the moment.',
        };
    }

    /** 🎚️ A transcript's state in words, for anybody reading a page. */
    public static function statusWords(string $status): string
    {
        return match ($status) {
            'queued'     => 'Waiting to be transcribed',
            'processing' => 'Being transcribed now',
            'completed'  => 'Ready to read',
            'failed'     => 'Could not be transcribed',
            default      => 'Not transcribed',
        };
    }

    /**
     * 🎚️ Whether the chosen company has what it needs to be called.
     *
     * Queueing without this would spend nothing and look like a fault: the
     * sweep would mark the row `failed` with `openai-not-configured`, which
     * reads to a customer like the recording was the problem.
     *
     * @param array<string,mixed> $settings The `transcription` sub-array.
     */
    public static function providerIsReady(array $settings): bool
    {
        return match ((string) ($settings['provider'] ?? 'openai')) {
            'openai'     => ((string) ($settings['openai']['apiKey'] ?? '')) !== '',
            'assemblyai' => ((string) ($settings['assemblyai']['apiKey'] ?? '')) !== '',
            // A path on disk, so this is the one provider whose readiness
            // cannot be answered from the settings alone.
            'local'      => is_executable((string) ($settings['local']['binPath'] ?? '')) === true,
            default      => false,
        };
    }

    // -------------------------------------------------------------------------
    // 📖 Reading a transcript's state — ONE place, so pages agree
    // -------------------------------------------------------------------------

    /**
     * 📖 What is known about one recording's transcript, or null when there has
     * never been one.
     *
     * `stillRunning` is worked out BY THE DATABASE, against the same clock the
     * column was written on, so no PHP time zone enters into it.
     *
     * @return array{status:string, stillRunning:bool, startedAt:?string, generatedAt:?string, errorMsg:?string, costPence:?int, hasText:bool}|null
     */
    public static function stateFor(int $recordingId, int $siteId): ?array
    {
        $db   = App::db();
        $stmt = $db->prepare(
            'SELECT t.status, t.startedAt, t.generatedAt, t.errorMsg, t.costPence, '
            . '       (t.fullText IS NOT NULL AND t.fullText <> \'\') AS hasText, '
            . '       (t.status = \'processing\' AND t.startedAt IS NOT NULL '
            . '        AND TIMESTAMPDIFF(MINUTE, t.startedAt, NOW()) < ?) AS stillRunning '
            . 'FROM tblTranscript t INNER JOIN tblRecording r ON r.recordingID = t.recordingID '
            . 'WHERE t.recordingID = ? AND r.siteID = ? LIMIT 1'
        );
        if ($stmt === false) {
            return null;
        }
        $minutes = self::STUCK_AFTER_MINUTES;
        $stmt->bind_param('iii', $minutes, $recordingId, $siteId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row === null) {
            return null;
        }

        return [
            'status'       => (string) $row['status'],
            'stillRunning' => (int) $row['stillRunning'] === 1,
            'startedAt'    => $row['startedAt'] !== null ? (string) $row['startedAt'] : null,
            'generatedAt'  => $row['generatedAt'] !== null ? (string) $row['generatedAt'] : null,
            'errorMsg'     => $row['errorMsg'] !== null ? (string) $row['errorMsg'] : null,
            'costPence'    => $row['costPence'] !== null ? (int) $row['costPence'] : null,
            'hasText'      => (int) $row['hasText'] === 1,
        ];
    }

    /**
     * 📖 The queue, counted for ONE organisation.
     *
     * `stuck` is not a status in the database: it is the number of rows that
     * say `processing` but were handed over too long ago to still be running.
     * An administrator needs it, because such a row looks busy for ever and is
     * the one thing that explains a recording that never finishes.
     *
     * @return array{queued:int, processing:int, completed:int, failed:int, stuck:int}
     */
    public static function queueStatsFor(int $siteId): array
    {
        $stats = ['queued' => 0, 'processing' => 0, 'completed' => 0, 'failed' => 0, 'stuck' => 0];
        $db    = App::db();
        $stmt  = $db->prepare(
            'SELECT t.status, COUNT(*) AS n, '
            . '       SUM(t.status = \'processing\' AND (t.startedAt IS NULL '
            . '           OR TIMESTAMPDIFF(MINUTE, t.startedAt, NOW()) >= ?)) AS wedged '
            . 'FROM tblTranscript t INNER JOIN tblRecording r ON r.recordingID = t.recordingID '
            . 'WHERE r.siteID = ? GROUP BY t.status'
        );
        if ($stmt === false) {
            return $stats;
        }
        $minutes = self::STUCK_AFTER_MINUTES;
        $stmt->bind_param('ii', $minutes, $siteId);
        $stmt->execute();
        $rs = $stmt->get_result();
        while (($row = $rs->fetch_assoc()) !== null) {
            $status = (string) $row['status'];
            if (array_key_exists($status, $stats) === true) {
                $stats[$status] = (int) $row['n'];
            }
            $stats['stuck'] += (int) $row['wedged'];
        }
        $stmt->close();

        return $stats;
    }

    /**
     * 📖 The most recent transcripts of ONE organisation.
     *
     * Scoped on purpose. These rows carry recording TITLES, and an
     * installation may serve organisations that have nothing to do with one
     * another — so one organisation's administrator must not read another's
     * list. The same is true of the counts above and of the month's spend.
     *
     * @return list<array<string,mixed>>
     */
    public static function recentFor(int $siteId, int $limit = 30): array
    {
        $limit = max(1, min($limit, 200));
        $rows  = [];
        $db    = App::db();
        $stmt  = $db->prepare(
            'SELECT t.transcriptID, t.recordingID, t.provider, t.status, t.durationSec, t.costPence, '
            . '       t.generatedAt, t.startedAt, t.errorMsg, r.title '
            . 'FROM tblTranscript t INNER JOIN tblRecording r ON r.recordingID = t.recordingID '
            . 'WHERE r.siteID = ? ORDER BY t.queuedAt DESC, t.transcriptID DESC LIMIT ?'
        );
        if ($stmt === false) {
            return [];
        }
        $stmt->bind_param('ii', $siteId, $limit);
        $stmt->execute();
        $rs = $stmt->get_result();
        while (($row = $rs->fetch_assoc()) !== null) {
            $rows[] = $row;
        }
        $stmt->close();

        return $rows;
    }

    /**
     * 🎬 Queue a recording for transcription — taking the same lock the sweep
     * takes, and refusing to re-queue a job that is still with the provider.
     *
     * Returns one of:
     *   'queued'   — there was no transcript. There is one now, waiting.
     *   'requeued' — there was one (finished, failed, waiting, or abandoned
     *                part-way) and it has been put back in the queue.
     *   'busy'     — the audio is with the transcription company right now.
     *                NOT a fault: asking again would send the same audio a
     *                second time and be charged for it twice.
     *   'failed'   — nothing was written.
     *
     * WHAT THIS USED TO DO, AND WHY IT HAD TO CHANGE
     * -------------------------------------------------------------------------
     * It was one `INSERT … ON DUPLICATE KEY UPDATE … status = "queued"`, with
     * no lock and no test of what the row already said. That was harmless only
     * because nothing in the portal ever called it — there was no button on any
     * page. Adding one makes the gap real: a transcript flipped to `processing`
     * is then in a single HTTP call that lasts up to ten minutes (OpenAI) or
     * twenty-five (AssemblyAI), and anybody pressing the button again during it
     * put the row back to `queued` for a second run to pick up. Same audio,
     * two bills, two writes of the same text, and nothing to say so.
     *
     * WHY THE LOCK IS ON THE RECORDING AND NOT ON THE TRANSCRIPT
     * -------------------------------------------------------------------------
     * There may be no transcript row yet, and there is nothing to lock in a row
     * that is not there. The recording always exists, so BOTH writers of the
     * queue state take THAT row's lock — this method, and the flip to
     * `processing` in `processQueue()`. That is what makes them take turns.
     *
     * Locking only here would not be enough, and the interleaving is worth
     * spelling out because it looks safe: this method reads `queued` and
     * decides to re-queue; the sweep flips the row to `processing` and starts
     * billing; this method then writes `queued` over it; the next sweep bills
     * the same audio again. Both sides have to hold the same lock for the
     * refusal below to mean anything.
     *
     * The provider call itself happens OUTSIDE any transaction, in
     * `processQueue()`. A lock must never be held across a ten-minute HTTP
     * request.
     *
     * WHAT IS DELIBERATELY LEFT ALONE ON A RE-QUEUE
     * -------------------------------------------------------------------------
     * `fullText`, `jsonSegments` and `generatedAt` are NOT cleared. They are
     * the truth about the last transcript that finished, and they stay readable
     * while a new one is made; `status` is the truth about the job. Clearing
     * `generatedAt` but keeping the text would leave a transcript on screen
     * with no date against it.
     */
    public static function queue(int $recordingId, string $provider): string
    {
        $db = App::db();
        $db->begin_transaction();
        try {
            // 🔒 Hold the recording still. Whether the recording exists and
            //    belongs to the right organisation is the CALLER's business:
            //    `recordings/transcribe` checks it just BEFORE calling this —
            //    outside this lock, not inside it. An earlier version of this
            //    comment said "inside this same lock", which was simply untrue.
            //    It would matter only if a recording could change organisation
            //    between the two statements, which nothing in the portal does;
            //    said accurately rather than left as a comfortable fiction.
            $stmt = $db->prepare('SELECT recordingID FROM tblRecording WHERE recordingID = ? FOR UPDATE');
            if ($stmt === false) {
                $db->rollback();

                return 'failed';
            }
            $stmt->bind_param('i', $recordingId);
            $stmt->execute();
            $exists = $stmt->get_result()->fetch_row() !== null;
            $stmt->close();
            if ($exists === false) {
                $db->rollback();

                return 'failed';
            }

            // 📖 The decision is made on what the database says INSIDE the
            //    lock, never on what a page worked out before it.
            $stmt = $db->prepare(
                'SELECT transcriptID, '
                . '       (status = \'processing\' AND startedAt IS NOT NULL '
                . '        AND TIMESTAMPDIFF(MINUTE, startedAt, NOW()) < ?) AS stillRunning '
                . 'FROM tblTranscript WHERE recordingID = ? LIMIT 1'
            );
            if ($stmt === false) {
                $db->rollback();

                return 'failed';
            }
            $minutes = self::STUCK_AFTER_MINUTES;
            $stmt->bind_param('ii', $minutes, $recordingId);
            $stmt->execute();
            $before = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($before !== null && (int) $before['stillRunning'] === 1) {
                $db->rollback();

                return 'busy';
            }

            // 🧹 `startedAt` back to NULL, because this job has not been handed
            //    to anybody yet. Leaving the old moment there would make the
            //    next reader think a run from hours ago was still going, and
            //    after two hours it would read as abandoned.
            $stmt = $db->prepare(
                'INSERT INTO tblTranscript (recordingID, provider, status, queuedAt, startedAt) '
                . 'VALUES (?, ?, \'queued\', NOW(), NULL) '
                . 'ON DUPLICATE KEY UPDATE provider = VALUES(provider), status = \'queued\', '
                . 'queuedAt = NOW(), startedAt = NULL, errorMsg = NULL'
            );
            if ($stmt === false) {
                $db->rollback();

                return 'failed';
            }
            $stmt->bind_param('is', $recordingId, $provider);
            $written = $stmt->execute();
            $stmt->close();
            if ($written === false) {
                $db->rollback();

                return 'failed';
            }

            $db->commit();

            return $before === null ? 'queued' : 'requeued';
        } catch (\Throwable $e) {
            // The connection is strict (DbServer::connect), so a statement
            // that cannot run throws rather than answering false. Roll back
            // and say nothing was written — never the fault's own words, which
            // can quote the row.
            $db->rollback();

            return 'failed';
        }
    }

    /**
     * 🏭 Drain up to `$cap` queued transcripts belonging to ONE organisation.
     *
     * Returns ['done' => N, 'failed' => N, 'skipped' => N, 'stoppedByCap' =>
     * bool]. `skipped` counts rows another run had already claimed;
     * `stoppedByCap` says the month's budget ran out part-way, which an
     * administrator needs told rather than left to infer from a short run.
     *
     * TWO THINGS CHANGED HERE, BOTH ABOUT SOMEBODY ELSE'S MONEY
     * -------------------------------------------------------------------------
     * 1. IT IS SCOPED TO ONE ORGANISATION. It used to take every queued row on
     *    the installation. `admin/transcription` is reachable by any
     *    organisation's administrator, so pressing "Process next 5" there sent
     *    ANOTHER organisation's audio to the provider and charged the
     *    installation's one API key for it. On an installation run by a hosting
     *    provider for unrelated customers, that is one customer spending
     *    another's budget on a third party's recordings.
     *
     * 2. THE CLAIM IS TAKEN UNDER THE RECORDING'S LOCK, and records WHEN. The
     *    conditional `UPDATE … WHERE status = 'queued'` was already here and
     *    was already right as far as it went; what it could not do was stop
     *    `queue()` putting the row back to `queued` immediately afterwards. The
     *    two now take the same lock, so they take turns. See `queue()` for the
     *    interleaving that used to be possible.
     *
     * THE LOCK IS NOT HELD ACROSS THE PROVIDER CALL
     * -------------------------------------------------------------------------
     * The transaction is opened, the claim is taken, and it is committed —
     * all before `providerTranscribe()` is called. That call can last
     * twenty-five minutes. Holding a row lock across it would block every
     * edit of that recording, and on a shared host the request would be killed
     * with the lock still held.
     *
     * WHAT THIS CANNOT DO
     * -------------------------------------------------------------------------
     * It cannot finish a job the host kills part-way. The row stays
     * `processing`, and after `STUCK_AFTER_MINUTES` it reads as abandoned so it
     * can be queued again. **That attempt may well have been charged for**, and
     * nothing here can say whether it was: a request can die before the
     * provider is reached, while it is transcribing, or after it has finished
     * and before the answer is written down. Saying flatly that the money is
     * spent would be as wrong as saying it is not.
     */
    public static function processQueue(int $cap, int $siteId): array
    {
        // 🔢 Clamped HERE, not only where it is read from the settings. This is
        //    the one place the number is used, and it goes straight into a
        //    `LIMIT`: a stored 0 would make every run silently do nothing, and
        //    a negative one would make the statement fail. `save.php` already
        //    clamps what it writes, but a row written any other way must not be
        //    able to break this.
        $cap             = max(1, min(50, $cap));
        $db              = App::db();
        $settings        = App::settings()['transcription'] ?? [];
        $defaultProvider = (string) ($settings['provider'] ?? 'openai');

        // 💳 What this organisation may spend this month. The installation's
        //    own layer is read ON ITS OWN, because for a limit the
        //    installation's value caps the organisation's and the merged array
        //    cannot tell the two apart (#668).
        $capPence = self::capFrom(App::settings(), InstallationSettings::layer());

        $jobs = [];
        $stmt = $db->prepare(
            'SELECT t.transcriptID, t.recordingID, t.provider, r.filePath, r.mimeType, r.siteID '
            . 'FROM tblTranscript t INNER JOIN tblRecording r ON r.recordingID = t.recordingID '
            . 'WHERE t.status = \'queued\' AND r.siteID = ? ORDER BY t.queuedAt, t.transcriptID LIMIT ?'
        );
        if ($stmt !== false) {
            $stmt->bind_param('ii', $siteId, $cap);
            $stmt->execute();
            $rs = $stmt->get_result();
            while (($row = $rs->fetch_assoc()) !== null) {
                $jobs[] = $row;
            }
            $stmt->close();
        }

        $done         = 0;
        $failed       = 0;
        $skipped      = 0;
        $stoppedByCap = false;

        foreach ($jobs as $job) {
            $tid         = (int) $job['transcriptID'];
            $recordingId = (int) $job['recordingID'];

            // 💳 Asked again before EVERY job, not once before the batch: each
            //    finished job adds its cost, so a long batch has to be able to
            //    stop part-way. The remaining rows stay queued and are picked
            //    up next month, or sooner if the cap is raised.
            if (self::monthSpendPence($siteId) >= $capPence) {
                $stoppedByCap = true;
                break;
            }

            // 🔒 Claim the row under the recording's lock, and record the
            //    moment, so `queue()` cannot put it back while the provider has
            //    it. The transaction ends here — nothing long-running is inside
            //    it.
            $claimed = false;
            $db->begin_transaction();
            try {
                $lock = $db->prepare('SELECT recordingID FROM tblRecording WHERE recordingID = ? FOR UPDATE');
                if ($lock !== false) {
                    $lock->bind_param('i', $recordingId);
                    $lock->execute();
                    $lock->get_result();
                    $lock->close();
                }
                $claim = $db->prepare(
                    'UPDATE tblTranscript SET status = \'processing\', startedAt = NOW() '
                    . 'WHERE transcriptID = ? AND status = \'queued\''
                );
                if ($claim !== false) {
                    $claim->bind_param('i', $tid);
                    $claim->execute();
                    $claimed = $claim->affected_rows === 1;
                    $claim->close();
                }
                $db->commit();
            } catch (\Throwable $e) {
                $db->rollback();
                $claimed = false;
            }
            if ($claimed === false) {
                // Another run got there first, or the row moved on. Not a
                // fault, and not a transcript either.
                $skipped++;
                continue;
            }

            // 🛟 ONE BAD ROW MUST NOT END THE BATCH.
            //
            //    Everything from here to the end of the loop is inside a try.
            //    Without it, anything that throws — and on a strict connection
            //    that includes any statement that cannot run — escapes as a
            //    bare 500: the administrator sees a blank page, the rows that
            //    already finished go unreported, and the row in hand sits at
            //    `processing` until it reads as abandoned two hours later.
            //
            //    That is not hypothetical. It is exactly what the reserved-word
            //    fault in the completing UPDATE below did, and it was the
            //    independent check that pointed out this loop had nothing to
            //    contain it.
            //
            //    A fault here becomes a FAILED row with a short fixed reason,
            //    so the run carries on and reports what happened.
            try {
                $path = $job['filePath'] !== null
                    ? Recordings::uploadDir() . DIRECTORY_SEPARATOR . basename((string) $job['filePath'])
                    : '';

                if ($path === '' || is_file($path) === false) {
                    self::markFailed($tid, 'no-file');
                    $failed++;
                    continue;
                }

                $provider = (string) ($job['provider'] ?? $defaultProvider);
                $result   = self::providerTranscribe($provider, $settings, $path, (string) ($job['mimeType'] ?? 'audio/mpeg'));
                if ($result['ok'] !== true) {
                    self::markFailed($tid, $result['error'] ?? 'provider-error');
                    $failed++;
                    continue;
                }

                $text     = (string) $result['text'];
                $segments = $result['segments'] !== null ? json_encode($result['segments']) : null;
                $language = (string) ($result['language'] ?? ((string) ($settings['language'] ?? 'en')));
                $duration = (int) ($result['duration'] ?? 0);
                $cost     = (int) ($result['costPence'] ?? 0);

                // 🧨 `fullText` IS BACKQUOTED BECAUSE IT HAS TO BE. `FULLTEXT` is a
                //    reserved word in MySQL, and keywords are matched without
                //    regard to case, so a BARE `fullText = ?` in a SET clause does
                //    not parse — it is read as the keyword. This statement was
                //    written without the backquotes and therefore NEVER RAN: the
                //    connection is strict, so `prepare()` throws, and it throws
                //    AFTER the transcription company has been called and charged.
                //    Nothing noticed, because no page could queue a recording in
                //    the first place, so the statement was never reached.
                //
                //    The other two readers of this column escape it differently and
                //    are both fine: `t.fullText` is unambiguous once qualified by a
                //    table alias, which is what `stateFor()` and
                //    `recordings/transcript.php` do. Either form works; a bare one
                //    does not.
                $u = $db->prepare(
                    'UPDATE tblTranscript SET status = \'completed\', `fullText` = ?, jsonSegments = ?, '
                    . 'language = ?, durationSec = ?, costPence = ?, generatedAt = NOW(), errorMsg = NULL '
                    . 'WHERE transcriptID = ?'
                );
                if ($u !== false) {
                    // 'sssiii': the last three are whole numbers. This said
                    // 'ssssii', which bound the duration as a string — harmless,
                    // because MySQL converts it, but the types should say what the
                    // values are.
                    $u->bind_param('sssiii', $text, $segments, $language, $duration, $cost, $tid);
                    $u->execute();
                    $u->close();
                }

                // Mirror into the FULLTEXT search table.
                self::indexSearch($tid, (int) $job['recordingID'], (int) $job['siteID'], $text);

                $done++;
            } catch (\Throwable $e) {
                // 🙈 NEVER the fault's own words. A message from a statement or
                //    from a provider can quote what was sent to it, which here
                //    is somebody speaking. A short fixed reason is recorded
                //    instead, and `markFailed()` is itself wrapped, because the
                //    thing that threw may be the database.
                try {
                    self::markFailed($tid, 'internal-error');
                } catch (\Throwable $ignored) {
                    // Nothing more can be done for this row from here. It stays
                    // `processing` and reads as abandoned after
                    // STUCK_AFTER_MINUTES, which is the recoverable state.
                }
                $failed++;
            }
        }

        return ['done' => $done, 'failed' => $failed, 'skipped' => $skipped, 'stoppedByCap' => $stoppedByCap];
    }

    /**
     * Search transcripts across this site via the FULLTEXT mirror.
     * Returns [{recordingID, title, snippet}, …]. NATURAL LANGUAGE mode
     * is forgiving — partial words, no special syntax required.
     */
    public static function search(int $siteId, string $query): array
    {
        $db = App::db();
        $rows = [];
        $stmt = $db->prepare(
            'SELECT s.recordingID, s.body, r.title, r.recordedAt '
            . 'FROM tblTranscriptSearch s INNER JOIN tblRecording r ON r.recordingID = s.recordingID '
            . 'WHERE s.siteID = ? AND r.isPublished = 1 '
            . 'AND MATCH(s.body) AGAINST (? IN NATURAL LANGUAGE MODE) '
            . 'ORDER BY MATCH(s.body) AGAINST (? IN NATURAL LANGUAGE MODE) DESC LIMIT 30'
        );
        if ($stmt === false) {
            return [];
        }
        $stmt->bind_param('iss', $siteId, $query, $query);
        $stmt->execute();
        $rs = $stmt->get_result();
        while ($r = $rs->fetch_assoc()) {
            $r['snippet'] = self::buildSnippet((string) $r['body'], $query);
            unset($r['body']);
            $rows[] = $r;
        }
        $stmt->close();
        return $rows;
    }

    /**
     * 💳 What ONE organisation has spent on transcription this calendar month,
     * in pence.
     *
     * SCOPED, where it used to be installation-wide. `tblTranscript` has no
     * organisation of its own, so this joins through the recording to find it.
     * Before this, an organisation's administrator read a figure that included
     * every other organisation's spending on the same installation — and the
     * cap is checked against this number, so one busy organisation would have
     * stopped all the others from transcribing anything.
     *
     * The month boundary is the server's own calendar month, matching
     * `generatedAt`'s clock. `Sms::monthSpendPence()` and
     * `AiAssistant::monthSpendPence()` are the same shape.
     *
     * Only `completed` rows count. A failed job is not charged for, and a job
     * still running has no cost recorded yet — which means a very large batch
     * can carry the month a little past its cap before the next check sees it.
     * That is said plainly rather than fixed by guessing at a price before the
     * provider has quoted one.
     */
    public static function monthSpendPence(int $siteId): int
    {
        $db = App::db();
        $stmt = $db->prepare(
            'SELECT COALESCE(SUM(t.costPence), 0) FROM tblTranscript t '
            . 'INNER JOIN tblRecording r ON r.recordingID = t.recordingID '
            . 'WHERE r.siteID = ? AND t.status = \'completed\' '
            . '  AND t.generatedAt >= DATE_FORMAT(NOW(), \'%Y-%m-01\')'
        );
        if ($stmt === false) {
            return 0;
        }
        $stmt->bind_param('i', $siteId);
        $stmt->execute();
        $stmt->bind_result($spend);
        $stmt->fetch();
        $stmt->close();

        return (int) $spend;
    }

    // -------------------------------------------------------------------------
    // Providers
    // -------------------------------------------------------------------------

    /**
     * Returns ['ok'=>bool, 'text'=>string, 'segments'=>?array,
     *          'language'=>?string, 'duration'=>?int, 'costPence'=>?int,
     *          'error'=>?string].
     */
    private static function providerTranscribe(string $provider, array $settings, string $path, string $mime): array
    {
        switch ($provider) {
            case 'openai':
                return self::openaiTranscribe($settings, $path, $mime);
            case 'assemblyai':
                return self::assemblyaiTranscribe($settings, $path, $mime);
            case 'local':
                return self::localTranscribe($settings, $path);
            default:
                return ['ok' => false, 'error' => 'unknown-provider'];
        }
    }

    /**
     * OpenAI Whisper — multipart upload to /v1/audio/transcriptions.
     * Returns segments when response_format=verbose_json.
     *
     * @link https://platform.openai.com/docs/api-reference/audio/createTranscription
     */
    private static function openaiTranscribe(array $settings, string $path, string $mime): array
    {
        $key   = (string) ($settings['openai']['apiKey'] ?? '');
        $model = (string) ($settings['openai']['model'] ?? 'whisper-1');
        if ($key === '') {
            return ['ok' => false, 'error' => 'openai-not-configured'];
        }
        $cfile = curl_file_create($path, $mime, basename($path));
        $post = [
            'file'            => $cfile,
            'model'           => $model,
            'response_format' => 'verbose_json',
        ];
        $lang = (string) ($settings['language'] ?? '');
        if ($lang !== '') {
            $post['language'] = $lang;
        }
        $ch = curl_init('https://api.openai.com/v1/audio/transcriptions');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $key]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 600);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        // No curl_close() here: the handle has been an object since PHP 8.0 and is
        // freed when it goes out of scope. The call has done nothing since then,
        // and PHP 8.5 raises a "deprecated" notice every time it runs (#673).
        if ($resp === false || $code < 200 || $code >= 300) {
            return ['ok' => false, 'error' => 'openai-http-' . $code];
        }
        $body = json_decode((string) $resp, true);
        if (is_array($body) === false) {
            return ['ok' => false, 'error' => 'openai-bad-json'];
        }
        $text     = (string) ($body['text'] ?? '');
        $duration = (int) round((float) ($body['duration'] ?? 0));
        // whisper-1 list price ~$0.006/min → ~0.5p/min at current rates.
        $costPence = (int) ceil(($duration / 60.0) * 0.5);
        $segments = null;
        if (isset($body['segments']) === true && is_array($body['segments']) === true) {
            $segments = array_map(static fn (array $s): array => [
                'start' => (float) ($s['start'] ?? 0),
                'end'   => (float) ($s['end']   ?? 0),
                'text'  => trim((string) ($s['text'] ?? '')),
            ], $body['segments']);
        }
        return [
            'ok' => true,
            'text' => $text,
            'segments' => $segments,
            'language' => (string) ($body['language'] ?? ''),
            'duration' => $duration,
            'costPence' => $costPence,
        ];
    }

    /**
     * AssemblyAI — two-step: upload bytes, then poll the transcript job.
     * Polls every 5 s up to 25 minutes; longer recordings benefit from
     * a webhook (out of scope for this PR — set `webhook_url` instead).
     *
     * @link https://www.assemblyai.com/docs/api-reference/transcripts
     */
    private static function assemblyaiTranscribe(array $settings, string $path, string $mime): array
    {
        $key = (string) ($settings['assemblyai']['apiKey'] ?? '');
        if ($key === '') {
            return ['ok' => false, 'error' => 'assemblyai-not-configured'];
        }
        // 1. Upload bytes.
        $ch = curl_init('https://api.assemblyai.com/v2/upload');
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            return ['ok' => false, 'error' => 'file-open-failed'];
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_PUT, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['authorization: ' . $key, 'content-type: application/octet-stream']);
        curl_setopt($ch, CURLOPT_INFILE, $fh);
        curl_setopt($ch, CURLOPT_INFILESIZE, filesize($path));
        curl_setopt($ch, CURLOPT_UPLOAD, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 600);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        fclose($fh);
        if ($resp === false || $code !== 200) {
            return ['ok' => false, 'error' => 'assemblyai-upload-' . $code];
        }
        $upload = json_decode((string) $resp, true);
        $uploadUrl = is_array($upload) === true ? (string) ($upload['upload_url'] ?? '') : '';
        if ($uploadUrl === '') {
            return ['ok' => false, 'error' => 'assemblyai-upload-bad-json'];
        }

        // 2. Kick off transcription.
        $ch = curl_init('https://api.assemblyai.com/v2/transcript');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['audio_url' => $uploadUrl, 'speaker_labels' => true]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['authorization: ' . $key, 'content-type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $resp = curl_exec($ch);
        $job = json_decode((string) $resp, true);
        $jobId = is_array($job) === true ? (string) ($job['id'] ?? '') : '';
        if ($jobId === '') {
            return ['ok' => false, 'error' => 'assemblyai-create-failed'];
        }

        // 3. Poll until completed or 25-minute timeout.
        $deadline = time() + 1500;
        while (time() < $deadline) {
            sleep(5);
            $ch = curl_init('https://api.assemblyai.com/v2/transcript/' . rawurlencode($jobId));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['authorization: ' . $key]);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            $resp = curl_exec($ch);
            $status = json_decode((string) $resp, true);
            if (is_array($status) === false) {
                continue;
            }
            $st = (string) ($status['status'] ?? '');
            if ($st === 'completed') {
                $text     = (string) ($status['text'] ?? '');
                $duration = (int) round((float) ($status['audio_duration'] ?? 0));
                // AssemblyAI Core ~$0.37/hr → ~0.5p/min at current rates.
                $costPence = (int) ceil(($duration / 60.0) * 0.5);
                $segments = null;
                if (isset($status['words']) === true && is_array($status['words']) === true) {
                    $segments = array_map(static fn (array $w): array => [
                        'start' => (float) (($w['start'] ?? 0) / 1000),
                        'end'   => (float) (($w['end']   ?? 0) / 1000),
                        'text'  => (string) ($w['text'] ?? ''),
                    ], $status['words']);
                }
                return [
                    'ok' => true,
                    'text' => $text,
                    'segments' => $segments,
                    'language' => (string) ($status['language_code'] ?? ''),
                    'duration' => $duration,
                    'costPence' => $costPence,
                ];
            }
            if ($st === 'error') {
                return ['ok' => false, 'error' => 'assemblyai:' . substr((string) ($status['error'] ?? ''), 0, 120)];
            }
        }
        return ['ok' => false, 'error' => 'assemblyai-timeout'];
    }

    /**
     * Local whisper.cpp wrapper. Expects `whisper -m <model> -f <wav>
     * --output-json` to be installed on the server. For DreamHost
     * this is rarely viable — it's here for self-hosted deployments.
     */
    private static function localTranscribe(array $settings, string $path): array
    {
        $bin = (string) ($settings['local']['binPath'] ?? '/usr/local/bin/whisper');
        if (is_executable($bin) === false) {
            return ['ok' => false, 'error' => 'local-not-installed'];
        }
        $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'whisper-' . bin2hex(random_bytes(4));
        $cmd = escapeshellcmd($bin) . ' ' . escapeshellarg($path) . ' --output-json ' . escapeshellarg($tmp) . ' 2>&1';
        $exit = 0;
        $out = [];
        exec($cmd, $out, $exit);
        if ($exit !== 0) {
            return ['ok' => false, 'error' => 'local-exit-' . $exit];
        }
        $json = @file_get_contents($tmp . '.json');
        if ($json === false) {
            return ['ok' => false, 'error' => 'local-no-json'];
        }
        $decoded = json_decode($json, true);
        $text    = is_array($decoded) === true ? (string) ($decoded['text'] ?? '') : '';
        return [
            'ok' => $text !== '',
            'text' => $text,
            'segments' => null,
            'language' => (string) ($settings['language'] ?? 'en'),
            'duration' => 0,
            'costPence' => 0,
        ];
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private static function markFailed(int $transcriptId, string $error): void
    {
        $db = App::db();
        $err = substr($error, 0, 250);
        $u = $db->prepare('UPDATE tblTranscript SET status = "failed", errorMsg = ? WHERE transcriptID = ?');
        if ($u !== false) {
            $u->bind_param('si', $err, $transcriptId);
            $u->execute();
            $u->close();
        }
    }

    private static function indexSearch(int $transcriptId, int $recordingId, int $siteId, string $body): void
    {
        $db = App::db();
        $stmt = $db->prepare(
            'INSERT INTO tblTranscriptSearch (transcriptID, recordingID, siteID, body) '
            . 'VALUES (?, ?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE body = VALUES(body), recordingID = VALUES(recordingID), siteID = VALUES(siteID)'
        );
        if ($stmt !== false) {
            $stmt->bind_param('iiis', $transcriptId, $recordingId, $siteId, $body);
            $stmt->execute();
            $stmt->close();
        }
    }

    /**
     * Highlight a short window around the first match of any query term.
     */
    private static function buildSnippet(string $body, string $query): string
    {
        $terms = preg_split('/\s+/', trim($query));
        $lower = strtolower($body);
        $pos = false;
        foreach ((array) $terms as $t) {
            $t = strtolower(trim($t));
            if ($t === '') {
                continue;
            }
            $p = strpos($lower, $t);
            if ($p !== false && ($pos === false || $p < $pos)) {
                $pos = $p;
            }
        }
        if ($pos === false) {
            return mb_substr($body, 0, 240) . '…';
        }
        $start   = max(0, $pos - 80);
        $excerpt = mb_substr($body, $start, 240);
        return ($start > 0 ? '… ' : '') . $excerpt . '…';
    }
}
