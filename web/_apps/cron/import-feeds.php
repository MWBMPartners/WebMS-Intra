<?php
// Path: _apps/cron/import-feeds.php
/**
 * -----------------------------------------------------------------------------
 * Cron — Import external ICS feeds (#327)
 * -----------------------------------------------------------------------------
 * Iterates every isActive=1 feed whose lastFetchedAt < NOW() - fetchEveryMins.
 * Fetches the URL via curl, parses VEVENT blocks with a minimal in-house
 * parser (no Composer), upserts into tblEvents keyed by (externalFeedID,
 * externalUid).
 *
 * Minimal parser supports: SUMMARY, DTSTART, DTEND, LOCATION, DESCRIPTION,
 * UID, STATUS. Unfolds soft-wrapped lines per RFC 5545.
 *
 * @link https://github.com/MWBMPartners/webMS-Intra/issues/327
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\Logger;
use Portal\Core\Settings;

$incoming = (string) ($_GET['key'] ?? '');
$expected = (string) (Settings::get('feeds.cron_token', '') ?? '');
if ($expected === '' || hash_equals($expected, $incoming) === false) {
    http_response_code(403); exit('Forbidden');
}

header('Content-Type: text/plain; charset=utf-8');

function parseIcsDate(string $val): ?string
{
    $val = trim($val);
    // YYYYMMDDTHHMMSSZ / YYYYMMDDTHHMMSS / YYYYMMDD
    if (preg_match('/^(\d{4})(\d{2})(\d{2})(?:T(\d{2})(\d{2})(\d{2})Z?)?$/', $val, $m) !== 1) {
        return null;
    }
    $ts = sprintf('%s-%s-%s %s:%s:%s', $m[1], $m[2], $m[3], $m[4] ?? '00', $m[5] ?? '00', $m[6] ?? '00');
    return $ts;
}

function parseIcs(string $body): array
{
    // 📜 Unfold continuation lines (RFC 5545 §3.1: lines starting with whitespace
    //     are continuations of the previous line).
    $body = preg_replace('/\r\n[ \t]/', '', $body);
    $body = preg_replace('/\n[ \t]/', '', (string) $body);
    $lines = preg_split('/\r\n|\n/', (string) $body) ?: [];

    $events = [];
    $current = null;
    foreach ($lines as $line) {
        if ($line === 'BEGIN:VEVENT') { $current = []; continue; }
        if ($line === 'END:VEVENT')   {
            if ($current !== null) { $events[] = $current; }
            $current = null;
            continue;
        }
        if ($current === null) { continue; }
        $colon = strpos($line, ':');
        if ($colon === false) { continue; }
        $left  = substr($line, 0, $colon);
        $value = substr($line, $colon + 1);
        $name  = strtoupper(explode(';', $left)[0]);
        // Decode \n, \,, \;
        $value = str_replace(['\\n', '\\,', '\\;', '\\\\'], ["\n", ',', ';', '\\'], $value);
        $current[$name] = $value;
    }
    return $events;
}

$now = time();
$processed = 0;
$totalImported = 0;
$result = $mysqli->query('SELECT feedID, siteID, url, fetchEveryMins FROM tblExternalFeeds WHERE isActive = 1');
$feeds = [];
while ($r = $result->fetch_assoc()) { $feeds[] = $r; }

foreach ($feeds as $f) {
    $feedId = (int) $f['feedID'];
    $siteId = (int) $f['siteID'];

    // ⏰ Due?
    $stm = $mysqli->prepare('SELECT lastFetchedAt FROM tblExternalFeeds WHERE feedID = ?');
    $stm->bind_param('i', $feedId);
    $stm->execute();
    $last = $stm->get_result()->fetch_assoc()['lastFetchedAt'] ?? null;
    $stm->close();
    if ($last !== null && (strtotime((string) $last) + ((int) $f['fetchEveryMins'] * 60)) > $now) {
        continue;
    }

    // 🌐 Fetch.
    $ch = curl_init((string) $f['url']);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_USERAGENT      => 'WebMS-Intra Feed Importer (https://github.com/MWBMPartners/WebMS-Intra)',
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    $status = 'OK';
    $imported = 0;
    if ($body === false || $code >= 400) {
        $status = 'HTTP ' . $code . ' ' . substr($err, 0, 120);
    } else {
        $events = parseIcs((string) $body);
        $upsert = $mysqli->prepare(
            'INSERT INTO tblEvents (siteID, externalFeedID, externalUid, eventName, description, '
            . '                     startDateTime, endDateTime, locationName, status, isPublic, isDeleted, eventSlug) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, "published", 1, 0, ?) '
            . 'ON DUPLICATE KEY UPDATE eventName = VALUES(eventName), description = VALUES(description), '
            . '                       startDateTime = VALUES(startDateTime), endDateTime = VALUES(endDateTime), '
            . '                       locationName = VALUES(locationName)'
        );
        foreach ($events as $e) {
            $uid    = mb_substr((string) ($e['UID'] ?? ''), 0, 255);
            if ($uid === '') { continue; }
            $name   = mb_substr((string) ($e['SUMMARY'] ?? '(Untitled)'), 0, 255);
            $desc   = mb_substr((string) ($e['DESCRIPTION'] ?? ''), 0, 5000);
            $loc    = mb_substr((string) ($e['LOCATION'] ?? ''), 0, 255);
            $start  = parseIcsDate((string) ($e['DTSTART'] ?? ''));
            $end    = parseIcsDate((string) ($e['DTEND'] ?? $e['DTSTART'] ?? ''));
            if ($start === null) { continue; }
            $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name . '-' . substr(md5($uid), 0, 6)) ?? '');
            $slug = trim((string) $slug, '-');
            $slug = mb_substr($slug, 0, 80);
            $upsert->bind_param('iisssssss', $siteId, $feedId, $uid, $name, $desc, $start, $end, $loc, $slug);
            @$upsert->execute();
            $imported++;
        }
        $upsert->close();
    }

    // 📋 Stamp the feed row.
    $stm = $mysqli->prepare('UPDATE tblExternalFeeds SET lastFetchedAt = NOW(), lastFetchStatus = ?, lastImportCount = ? WHERE feedID = ?');
    $stm->bind_param('sii', $status, $imported, $feedId);
    $stm->execute();
    $stm->close();

    Logger::activity('FeedImported', 'Feed #' . $feedId . ' status=' . $status . ' n=' . $imported);
    $processed++;
    $totalImported += $imported;
}

echo 'OK processed=' . $processed . ' imported=' . $totalImported;
