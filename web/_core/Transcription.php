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
 * Async queue model: uploading a recording or hitting "transcribe" inserts a
 * tblTranscript row with status=queued; an admin / cron sweep calls
 * Transcription::processQueue($cap) which dispatches up to `batchSize` rows
 * through the configured provider.
 *
 * Results are persisted in two places: the canonical `tblTranscript.fullText
 * + jsonSegments`, and a denormalised mirror in `tblTranscriptSearch.body`
 * with a MySQL FULLTEXT index for `/recordings/search`.
 *
 * @package   Portal\Core
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/276
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

class Transcription
{
    /**
     * Queue a recording for transcription (idempotent — re-queuing a
     * completed transcript flips it to "queued" for re-run).
     */
    public static function queue(int $recordingId, string $provider): bool
    {
        $db = App::db();
        $stmt = $db->prepare(
            'INSERT INTO tblTranscript (recordingID, provider, status, queuedAt) VALUES (?, ?, "queued", NOW()) '
            . 'ON DUPLICATE KEY UPDATE provider = VALUES(provider), status = "queued", queuedAt = NOW(), errorMsg = NULL'
        );
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param('is', $recordingId, $provider);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    /**
     * Drain up to `$cap` queued transcripts. Returns ['done' => N,
     * 'failed' => N]. Each row is flipped to "processing" before the API
     * call so concurrent invocations don't double-bill.
     */
    public static function processQueue(int $cap): array
    {
        $db = App::db();
        $settings = App::settings()['transcription'] ?? [];
        $defaultProvider = (string) ($settings['provider'] ?? 'openai');

        $jobs = [];
        $stmt = $db->prepare(
            'SELECT t.transcriptID, t.recordingID, t.provider, r.filePath, r.mimeType, r.siteID '
            . 'FROM tblTranscript t INNER JOIN tblRecording r ON r.recordingID = t.recordingID '
            . 'WHERE t.status = "queued" ORDER BY t.queuedAt LIMIT ?'
        );
        if ($stmt !== false) {
            $stmt->bind_param('i', $cap);
            $stmt->execute();
            $rs = $stmt->get_result();
            while ($r = $rs->fetch_assoc()) {
                $jobs[] = $r;
            }
            $stmt->close();
        }

        $done = 0;
        $failed = 0;
        foreach ($jobs as $job) {
            $tid = (int) $job['transcriptID'];

            // Flip to processing so a parallel run skips it.
            $u = $db->prepare('UPDATE tblTranscript SET status = "processing" WHERE transcriptID = ? AND status = "queued"');
            if ($u !== false) {
                $u->bind_param('i', $tid);
                $u->execute();
                $skip = $u->affected_rows === 0;
                $u->close();
                if ($skip === true) {
                    continue;
                }
            }

            $provider = (string) ($job['provider'] ?? $defaultProvider);
            $path = $job['filePath'] !== null
                ? Recordings::uploadDir() . DIRECTORY_SEPARATOR . basename((string) $job['filePath'])
                : '';

            if ($path === '' || is_file($path) === false) {
                self::markFailed($tid, 'no-file');
                $failed++;
                continue;
            }

            $result = self::providerTranscribe($provider, $settings, $path, (string) ($job['mimeType'] ?? 'audio/mpeg'));
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

            $u = $db->prepare(
                'UPDATE tblTranscript SET status = "completed", fullText = ?, jsonSegments = ?, '
                . 'language = ?, durationSec = ?, costPence = ?, generatedAt = NOW(), errorMsg = NULL '
                . 'WHERE transcriptID = ?'
            );
            if ($u !== false) {
                $u->bind_param('ssssii', $text, $segments, $language, $duration, $cost, $tid);
                $u->execute();
                $u->close();
            }

            // Mirror into the FULLTEXT search table.
            self::indexSearch($tid, (int) $job['recordingID'], (int) $job['siteID'], $text);

            $done++;
        }

        return ['done' => $done, 'failed' => $failed];
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
     * Per-month cost rollup across all sites (in pence).
     */
    public static function monthSpendPence(): int
    {
        $db = App::db();
        $stmt = $db->prepare(
            'SELECT COALESCE(SUM(costPence), 0) FROM tblTranscript '
            . 'WHERE status = "completed" AND generatedAt >= DATE_FORMAT(NOW(), "%Y-%m-01")'
        );
        if ($stmt === false) {
            return 0;
        }
        $stmt->execute();
        $stmt->bind_result($s);
        $stmt->fetch();
        $stmt->close();
        return (int) $s;
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
        curl_close($ch);
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
        curl_close($ch);
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
        curl_close($ch);
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
            curl_close($ch);
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
