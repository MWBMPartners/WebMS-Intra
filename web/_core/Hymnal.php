<?php
// Path: _core/Hymnal.php
/**
 * -----------------------------------------------------------------------------
 * Hymnal lookup — local index + optional remote (iHymns) client 📖🔎
 * -----------------------------------------------------------------------------
 * Gap #128 residual (re-scoped "Order of Service planner with iHymns
 * integration" — service-plans + Worship already cover everything else the
 * issue asked for; see the plan doc referenced on the issue). Three tiers:
 *
 *   Tier 0 — Manual: free-text title on a run-sheet song item, unchanged.
 *   Tier 1 — Local index: `tblHymnals` + `tblHymnalEntries`, searched here
 *            via `searchLocal()`. Metadata only — NEVER lyrics (copyright).
 *   Tier 2 — Remote ("iHymns"): a generic, owner-configured HTTPS JSON
 *            client, default OFF (`hymns.remote.enabled`), searched via
 *            `searchRemote()`. Because no public iHymns API is documented,
 *            this defines the contract an owner-controlled endpoint must
 *            speak:
 *
 *              GET {baseUrl}/search?q={query}&hymnal={code?}&limit={n}
 *              -> 200 {"results":[{"id":"…","hymnal":"CH","number":"256",
 *                   "title":"…","firstLine":"…","author":"…","tune":"…",
 *                   "meter":"…","url":"…"}]}
 *
 * SSRF hardening on `searchRemote()`/`testRemoteConnection()` (mandatory —
 * this endpoint's base URL is owner-supplied, so it is treated as hostile
 * input at the network layer):
 *   • https:// only — enforced at settings-save time (Hymnal::validateRemoteConfig())
 *     AND re-checked here at request time.
 *   • Single-host allowlist — `hymns.remote.host` must match the
 *     configured `baseUrl`'s host EXACTLY (case-insensitive). No user-
 *     supplied URL is ever fetched; the operator can only choose from
 *     what an admin has configured in settings.
 *   • IP-literal / private / reserved-range hosts refused (both at save
 *     and again here — belt & braces; DNS-rebinding past this point is a
 *     documented residual risk, acceptable because the feature is
 *     default-off, owner-configured, single-host, GET-only). Since #514
 *     part P4 the "is this address private or reserved?" test itself is
 *     `SafeFetch::isPublicIp()`, shared with the outside-calendar importer,
 *     so the two features refuse exactly the same ranges. Before that this
 *     file used PHP's older NO_PRIV_RANGE | NO_RES_RANGE flags, which let
 *     through (tested on PHP 8.5.10) the providers' shared range
 *     100.64.0.0/10, multicast, the documentation and benchmark ranges,
 *     6to4, and the IPv6 spellings `::ffff:0:7f00:1` and `::7f00:1` that
 *     hold 127.0.0.1.
 *   • `CURLOPT_FOLLOWLOCATION = false` — a redirect is never followed.
 *   • `CURLOPT_PROTOCOLS` / `CURLOPT_REDIR_PROTOCOLS` locked to HTTPS.
 *   • Connect timeout 3s / total timeout 5s.
 *   • Response body capped (~512 KB) via a WRITEFUNCTION that aborts the
 *     transfer once exceeded.
 *   • JSON-only parse; any shape surprise is treated as "no results".
 *   • 24h server-side cache (`tblHymnLookupCache`) — a repeat query never
 *     re-hits the remote host while cached.
 *   • Graceful degradation — ANY failure (disabled, misconfigured, DNS,
 *     timeout, non-200, malformed JSON, oversized body) returns `[]` and
 *     logs a platform warning; it NEVER throws to the caller and NEVER
 *     blocks the local-index results from rendering.
 *   • The API key (if any) is sent only as a request header, is stored
 *     encrypted at rest (`isSensitive=1` — see migration 178), and is
 *     NEVER logged and NEVER echoed back to the client.
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026 MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/128
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

class Hymnal
{
    /** @var int Remote HTTP connect timeout, seconds. */
    private const REMOTE_CONNECT_TIMEOUT = 3;

    /** @var int Remote HTTP total timeout, seconds. */
    private const REMOTE_TOTAL_TIMEOUT = 5;

    /** @var int Hard cap on the remote response body, bytes (~512 KB). */
    private const REMOTE_MAX_BYTES = 524288;

    /** @var int Default cache TTL, seconds (24h) — overridden by hymns.remote.cacheTtl. */
    private const CACHE_TTL_DEFAULT = 86400;

    /** @var int CSV import — max upload size, bytes (256 KB; metadata rows are tiny). */
    public const CSV_MAX_BYTES = 262144;

    /** @var int CSV import — max data rows per upload. */
    public const CSV_MAX_ROWS = 5000;

    // -------------------------------------------------------------------
    // 🔎 Tier 1 — local index search
    // -------------------------------------------------------------------

    /**
     * Search this site's local hymnal index. Matches by exact/prefix hymn
     * number when `$q` looks numeric, and by FULLTEXT (title/firstLine/
     * author/tuneName) otherwise — falling back to a LIKE scan for very
     * short queries FULLTEXT would ignore (MySQL's default 50% stopword/
     * min-length behaviour).
     *
     * @return list<array<string, mixed>>
     */
    public static function searchLocal(int $siteId, string $q, ?int $hymnalId = null, int $limit = 20): array
    {
        $q = trim($q);
        if ($q === '' || $siteId <= 0) {
            return [];
        }
        $limit = max(1, min(50, $limit));
        $db    = App::db();

        $where  = 'h.siteID = ? AND h.isActive = 1';
        $types  = 'i';
        $params = [$siteId];

        if ($hymnalId !== null && $hymnalId > 0) {
            $where   .= ' AND e.hymnalID = ?';
            $types   .= 'i';
            $params[] = $hymnalId;
        }

        // 🔢 Numeric-looking query — search hymn number first (exact then prefix).
        $isNumeric = (bool) preg_match('/^\d+/', $q);

        if ($isNumeric === true) {
            $sql = 'SELECT e.entryID, e.number, e.title, e.firstLine, e.author, e.tuneName, e.meter, '
                . 'e.ccliNumber, e.copyrightLine, h.hymnalID, h.code AS hymnalCode, h.name AS hymnalName '
                . 'FROM tblHymnalEntries e INNER JOIN tblHymnals h ON h.hymnalID = e.hymnalID '
                . "WHERE $where AND e.number LIKE ? ORDER BY e.numberSort, e.number LIMIT ?";
            $needle    = $q . '%';
            $bindTypes = $types . 'si';
            $bindVals  = array_merge($params, [$needle, $limit]);
        } else {
            // 🔤 FULLTEXT with a LIKE fallback for short/stopword-only terms.
            $sql = 'SELECT e.entryID, e.number, e.title, e.firstLine, e.author, e.tuneName, e.meter, '
                . 'e.ccliNumber, e.copyrightLine, h.hymnalID, h.code AS hymnalCode, h.name AS hymnalName '
                . 'FROM tblHymnalEntries e INNER JOIN tblHymnals h ON h.hymnalID = e.hymnalID '
                . "WHERE $where AND ("
                . 'MATCH(e.title, e.firstLine, e.author, e.tuneName) AGAINST (? IN NATURAL LANGUAGE MODE) '
                . 'OR e.title LIKE ? OR e.firstLine LIKE ?'
                . ') ORDER BY e.numberSort, e.number LIMIT ?';
            $needle    = '%' . $q . '%';
            $bindTypes = $types . 'sssi';
            $bindVals  = array_merge($params, [$q, $needle, $needle, $limit]);
        }

        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            return [];
        }
        $stmt->bind_param($bindTypes, ...$bindVals);
        $stmt->execute();
        $rs   = $stmt->get_result();
        $rows = [];
        while ($r = $rs->fetch_assoc()) {
            $r['source'] = 'hymnal';
            $rows[] = $r;
        }
        $stmt->close();
        return $rows;
    }

    /**
     * List every active hymnal for a site (for the admin page + picker filter).
     *
     * @return list<array<string, mixed>>
     */
    public static function listHymnals(int $siteId, bool $activeOnly = true): array
    {
        $db  = App::db();
        $sql = 'SELECT hymnalID, code, name, publisher, isActive, '
             . '(SELECT COUNT(*) FROM tblHymnalEntries WHERE hymnalID = h.hymnalID) AS entryCount '
             . 'FROM tblHymnals h WHERE siteID = ?' . ($activeOnly === true ? ' AND isActive = 1' : '')
             . ' ORDER BY name';
        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            return [];
        }
        $stmt->bind_param('i', $siteId);
        $stmt->execute();
        $rows = [];
        $rs   = $stmt->get_result();
        while ($r = $rs->fetch_assoc()) {
            $rows[] = $r;
        }
        $stmt->close();
        return $rows;
    }

    // -------------------------------------------------------------------
    // 🌐 Tier 2 — remote (iHymns) search, SSRF-guarded, default OFF
    // -------------------------------------------------------------------

    /**
     * Search the configured remote provider — cache-first, SSRF-hardened,
     * never throws. Returns `[]` on ANY failure (disabled, misconfigured,
     * unreachable, malformed) — callers should treat that identically to
     * "no remote results" and keep showing local results.
     *
     * @return list<array<string, mixed>>
     */
    public static function searchRemote(int $siteId, string $q, int $limit = 20): array
    {
        $q = trim($q);
        if ($q === '' || $siteId <= 0) {
            return [];
        }

        if ((string) App::settings('hymns.remote.enabled') !== 'true') {
            return [];
        }
        $baseUrl = trim((string) App::settings('hymns.remote.baseUrl'));
        $host    = trim((string) App::settings('hymns.remote.host'));
        if ($baseUrl === '' || $host === '') {
            return [];
        }

        $urlParts = parse_url($baseUrl);
        if ($urlParts === false
            || (string) ($urlParts['scheme'] ?? '') !== 'https'
            || strcasecmp((string) ($urlParts['host'] ?? ''), $host) !== 0
        ) {
            // 🛡️ Misconfiguration (scheme drifted, or host no longer matches
            // the allowlist) — refuse silently rather than fetch something
            // that no longer matches what was configured/reviewed.
            Logger::errorPlatform('Hymnal', 'Warning', 'REMOTE_CONFIG', 'Remote hymnal base URL failed the https/host-allowlist check', '');
            return [];
        }
        if (self::isPrivateOrReservedHost((string) $urlParts['host']) === true) {
            Logger::errorPlatform('Hymnal', 'Warning', 'REMOTE_SSRF', 'Remote hymnal host resolves to a private/reserved address — refused', '');
            return [];
        }

        $limit    = max(1, min(20, $limit));
        $cacheTtl = (int) App::settings('hymns.remote.cacheTtl');
        if ($cacheTtl <= 0) {
            $cacheTtl = self::CACHE_TTL_DEFAULT;
        }
        $queryHash = hash('sha256', strtolower($q) . '|' . $limit);

        // 🗃️ Cache-first — a repeat query never re-hits the remote host.
        $cached = self::cacheGet($siteId, $queryHash, $cacheTtl);
        if ($cached !== null) {
            return $cached;
        }

        $endpoint = rtrim($baseUrl, '/') . '/search?' . http_build_query([
            'q'     => $q,
            'limit' => $limit,
        ]);

        $apiKey = (string) App::settings('hymns.remote.apiKey');
        $headers = ['Accept: application/json'];
        if ($apiKey !== '') {
            // 🔒 Sent as a header only — NEVER logged, NEVER echoed to the client.
            $headers[] = 'Authorization: Bearer ' . $apiKey;
        }

        $body = self::curlGetCapped($endpoint, $headers);
        if ($body === null) {
            return [];
        }

        $decoded = json_decode($body, true, 8, JSON_BIGINT_AS_STRING);
        if (is_array($decoded) === false || is_array($decoded['results'] ?? null) === false) {
            Logger::errorPlatform('Hymnal', 'Warning', 'REMOTE_PARSE', 'Remote hymnal response was not valid JSON in the expected shape', '');
            return [];
        }

        $results = [];
        foreach ($decoded['results'] as $r) {
            if (is_array($r) === false) {
                continue;
            }
            $results[] = [
                'source'        => 'remote',
                'sourceRef'     => isset($r['id']) === true ? (string) $r['id'] : (isset($r['url']) === true ? (string) $r['url'] : null),
                'hymnalCode'    => isset($r['hymnal']) === true ? mb_substr((string) $r['hymnal'], 0, 20) : null,
                'number'        => isset($r['number']) === true ? mb_substr((string) $r['number'], 0, 20) : '',
                'title'         => isset($r['title']) === true ? mb_substr((string) $r['title'], 0, 255) : '',
                'firstLine'     => isset($r['firstLine']) === true ? mb_substr((string) $r['firstLine'], 0, 255) : null,
                'author'        => isset($r['author']) === true ? mb_substr((string) $r['author'], 0, 255) : null,
                'tuneName'      => isset($r['tune']) === true ? mb_substr((string) $r['tune'], 0, 120) : null,
                'meter'         => isset($r['meter']) === true ? mb_substr((string) $r['meter'], 0, 40) : null,
                'ccliNumber'    => null,
                'copyrightLine' => null,
            ];
            if (count($results) >= $limit) {
                break;
            }
        }

        self::cachePut($siteId, $queryHash, $results);
        return $results;
    }

    /**
     * Admin "Test connection" button — CloudflareStream::testConnection()
     * parity (migration 158 precedent): machine-safe generic messages only,
     * never the remote provider's own raw response text.
     *
     * @return array{success: bool, message: string}
     */
    public static function testRemoteConnection(int $siteId): array
    {
        if ((string) App::settings('hymns.remote.enabled') !== 'true') {
            return ['success' => false, 'message' => 'Remote lookup is not enabled.'];
        }
        // Note: deliberately does NOT call searchRemote() — that method
        // degrades to [] on ANY failure, including "zero matches for a
        // real query", which would make a genuinely-reachable-but-empty
        // provider look like a failed test. Re-derives success directly
        // from the low-level fetch below instead (siteId is otherwise
        // unused here — kept in the signature for parity with every other
        // per-site remote-config accessor in this class).
        $baseUrl = trim((string) App::settings('hymns.remote.baseUrl'));
        $host    = trim((string) App::settings('hymns.remote.host'));
        if ($baseUrl === '' || $host === '') {
            return ['success' => false, 'message' => 'Base URL and host must both be set before testing.'];
        }
        $urlParts = parse_url($baseUrl);
        if ($urlParts === false || (string) ($urlParts['scheme'] ?? '') !== 'https'
            || strcasecmp((string) ($urlParts['host'] ?? ''), $host) !== 0
        ) {
            return ['success' => false, 'message' => 'Base URL must be https:// and match the configured host exactly.'];
        }
        if (self::isPrivateOrReservedHost((string) $urlParts['host']) === true) {
            return ['success' => false, 'message' => 'Configured host resolves to a private/reserved address — refused.'];
        }
        $endpoint = rtrim($baseUrl, '/') . '/search?' . http_build_query(['q' => 'test', 'limit' => 1]);
        $apiKey   = (string) App::settings('hymns.remote.apiKey');
        $headers  = ['Accept: application/json'];
        if ($apiKey !== '') {
            $headers[] = 'Authorization: Bearer ' . $apiKey;
        }
        $body = self::curlGetCapped($endpoint, $headers);
        if ($body === null) {
            return ['success' => false, 'message' => 'Could not reach the remote hymnal endpoint — check network connectivity and try again.'];
        }
        $decoded = json_decode($body, true, 8);
        if (is_array($decoded) === false || array_key_exists('results', $decoded) === false) {
            return ['success' => false, 'message' => 'Connected, but the response was not in the expected JSON shape ({"results":[...]})'];
        }
        return ['success' => true, 'message' => 'Connected — the remote endpoint returned a valid response.'];
    }

    /**
     * https-only + single-host-allowlist + non-private-IP validation for a
     * candidate `baseUrl`/`host` pair, called from the settings-save
     * handler BEFORE persisting either value (defence in depth alongside
     * the identical checks re-run at request time in searchRemote()).
     *
     * @return string Empty string when valid, else a human-readable reason.
     */
    public static function validateRemoteConfig(string $baseUrl, string $host): string
    {
        if ($baseUrl === '' && $host === '') {
            return '';
        }
        if ($baseUrl === '' || $host === '') {
            return 'Base URL and host must both be set (or both left blank).';
        }
        $parts = parse_url($baseUrl);
        if ($parts === false || (string) ($parts['scheme'] ?? '') !== 'https') {
            return 'Base URL must start with https://';
        }
        if (strcasecmp((string) ($parts['host'] ?? ''), $host) !== 0) {
            return 'Base URL host must exactly match the Host field.';
        }
        if (self::isPrivateOrReservedHost($host) === true) {
            return 'Host resolves to a private/reserved address and cannot be used.';
        }
        return '';
    }

    // -------------------------------------------------------------------
    // ⬆️ Promote a picked hymn/song into the canonical song library
    // -------------------------------------------------------------------

    /**
     * Upsert a picked hymnal entry (local OR remote) into `tblSongs` and
     * return its songID. Check-first on (siteID, hymnalCode, hymnNumber);
     * a duplicate-key race on re-pick is tolerated (returns the winning
     * row's id rather than erroring). Metadata only — never sets lyrics.
     *
     * @param array{title:string,author?:?string,ccliNumber?:?string,copyrightLine?:?string,hymnalCode?:?string,hymnNumber?:?string,tuneName?:?string} $entry
     */
    public static function promoteToSong(int $siteId, array $entry, ?int $userId = null): int
    {
        $title = trim((string) ($entry['title'] ?? ''));
        if ($siteId <= 0 || $title === '') {
            return 0;
        }
        $hymnalCode = self::nullableStr($entry['hymnalCode'] ?? null, 20);
        $hymnNumber = self::nullableStr($entry['hymnNumber'] ?? null, 20);
        $author     = self::nullableStr($entry['author'] ?? null, 255);
        $ccli       = self::nullableStr($entry['ccliNumber'] ?? null, 40);
        $copyright  = self::nullableStr($entry['copyrightLine'] ?? null, 500);
        $tune       = self::nullableStr($entry['tuneName'] ?? null, 120);
        $title      = mb_substr($title, 0, 255);

        $db = App::db();

        // 🔁 Check-first — only meaningful when we actually know the
        // hymnal identity; a bare free-text title always creates fresh
        // (nothing to de-dupe against safely).
        if ($hymnalCode !== null && $hymnNumber !== null) {
            $stmt = $db->prepare(
                'SELECT songID FROM tblSongs WHERE siteID = ? AND hymnalCode = ? AND hymnNumber = ? AND isActive = 1 LIMIT 1'
            );
            if ($stmt !== false) {
                $stmt->bind_param('iss', $siteId, $hymnalCode, $hymnNumber);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($row !== null) {
                    return (int) $row['songID'];
                }
            }
        }

        $uid = ($userId !== null && $userId > 0) ? $userId : null;
        $stmt = $db->prepare(
            'INSERT INTO tblSongs (siteID, title, author, ccliNumber, copyrightLine, hymnalCode, hymnNumber, tuneName, createdByID) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        if ($stmt === false) {
            return 0;
        }
        $stmt->bind_param('isssssssi', $siteId, $title, $author, $ccli, $copyright, $hymnalCode, $hymnNumber, $tune, $uid);
        try {
            $stmt->execute();
            $newId = (int) $stmt->insert_id;
            $stmt->close();
            return $newId;
        } catch (\mysqli_sql_exception $e) {
            $stmt->close();
            // 🔁 Duplicate-race tolerance — another request promoted the
            // same (siteID, hymnalCode, hymnNumber) a moment ago. There is
            // no UNIQUE key enforcing this (by design — see migration 178
            // header), so a genuine duplicate-key exception here can only
            // come from tblSongs' OTHER constraints; re-select defensively
            // rather than surface a 500 to the item-save flow.
            if ($hymnalCode !== null && $hymnNumber !== null) {
                $reselect = $db->prepare(
                    'SELECT songID FROM tblSongs WHERE siteID = ? AND hymnalCode = ? AND hymnNumber = ? ORDER BY songID LIMIT 1'
                );
                if ($reselect !== false) {
                    $reselect->bind_param('iss', $siteId, $hymnalCode, $hymnNumber);
                    $reselect->execute();
                    $row = $reselect->get_result()->fetch_assoc();
                    $reselect->close();
                    if ($row !== null) {
                        return (int) $row['songID'];
                    }
                }
            }
            Logger::exception($e);
            return 0;
        }
    }

    // -------------------------------------------------------------------
    // 📤 CSV import (admin/hymns-save.php)
    // -------------------------------------------------------------------

    /**
     * Parse + upsert a hymnal-index CSV into `tblHymnalEntries`. Expected
     * header row (order-independent, case-insensitive):
     *   number,title,firstLine,author,tuneName,meter,ccliNumber,copyrightLine
     * Only `number` and `title` are required; every other column is
     * optional. Upsert on (hymnalID, number) — idempotent re-import.
     *
     * @return array{ok:int, errors:list<string>}
     */
    public static function importCsv(int $siteId, int $hymnalId, string $rawCsv): array
    {
        $errors = [];
        if ($siteId <= 0 || $hymnalId <= 0) {
            return ['ok' => 0, 'errors' => ['Invalid hymnal.']];
        }

        // 🛡️ Confirm the hymnal belongs to this site before writing a
        // single row — a crafted hymnalID must never touch another tenant.
        $db = App::db();
        $check = $db->prepare('SELECT 1 FROM tblHymnals WHERE hymnalID = ? AND siteID = ? LIMIT 1');
        $check->bind_param('ii', $hymnalId, $siteId);
        $check->execute();
        $owns = $check->get_result()->fetch_row() !== null;
        $check->close();
        if ($owns === false) {
            return ['ok' => 0, 'errors' => ['Hymnal not found.']];
        }

        if (str_starts_with($rawCsv, "\xEF\xBB\xBF") === true) {
            $rawCsv = substr($rawCsv, 3);
        }
        if (mb_check_encoding($rawCsv, 'UTF-8') === false) {
            $rawCsv = (string) mb_convert_encoding($rawCsv, 'UTF-8', 'Windows-1252');
        }

        $fh = fopen('php://temp', 'r+');
        if ($fh === false) {
            return ['ok' => 0, 'errors' => ['Could not read the uploaded file.']];
        }
        fwrite($fh, $rawCsv);
        rewind($fh);
        $header = fgetcsv($fh);
        if ($header === false || count($header) === 0) {
            fclose($fh);
            return ['ok' => 0, 'errors' => ['Could not read a header row.']];
        }
        $header  = array_map(static fn ($h): string => strtolower(trim(str_replace(["\xEF\xBB\xBF", '"'], '', (string) $h))), $header);
        $colMap  = array_flip($header);
        $numIdx  = $colMap['number'] ?? null;
        $titIdx  = $colMap['title'] ?? null;
        if ($numIdx === null || $titIdx === null) {
            fclose($fh);
            return ['ok' => 0, 'errors' => ['CSV must contain "number" and "title" columns.']];
        }

        $get = static function (array $cells, ?int $idx): string {
            if ($idx === null || array_key_exists($idx, $cells) === false) {
                return '';
            }
            return trim((string) $cells[$idx]);
        };

        $stmt = $db->prepare(
            'INSERT INTO tblHymnalEntries (hymnalID, number, numberSort, title, firstLine, author, tuneName, meter, ccliNumber, copyrightLine) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE title = VALUES(title), numberSort = VALUES(numberSort), firstLine = VALUES(firstLine), '
            . 'author = VALUES(author), tuneName = VALUES(tuneName), meter = VALUES(meter), ccliNumber = VALUES(ccliNumber), '
            . 'copyrightLine = VALUES(copyrightLine)'
        );
        if ($stmt === false) {
            fclose($fh);
            return ['ok' => 0, 'errors' => ['Database error preparing the import.']];
        }

        $ok  = 0;
        $row = 1;
        while (($cells = fgetcsv($fh)) !== false && $row <= self::CSV_MAX_ROWS) {
            $row++;
            if (count(array_filter($cells, static fn ($v): bool => trim((string) $v) !== '')) === 0) {
                continue; // blank line
            }
            $number = mb_substr($get($cells, $numIdx), 0, 20);
            $title  = mb_substr($get($cells, $titIdx), 0, 255);
            if ($number === '' || $title === '') {
                $errors[] = "Row $row: number and title are both required — skipped.";
                continue;
            }
            $numberSort = (int) (preg_match('/^\d+/', $number, $m) === 1 ? $m[0] : 0);
            $firstLine  = self::nullableStr($get($cells, $colMap['firstline'] ?? null), 255);
            $author     = self::nullableStr($get($cells, $colMap['author'] ?? null), 255);
            $tune       = self::nullableStr($get($cells, $colMap['tunename'] ?? null), 120);
            $meter      = self::nullableStr($get($cells, $colMap['meter'] ?? null), 40);
            $ccli       = self::nullableStr($get($cells, $colMap['cclinumber'] ?? null), 40);
            $copyright  = self::nullableStr($get($cells, $colMap['copyrightline'] ?? null), 500);

            $stmt->bind_param(
                'isisssssss',
                $hymnalId,
                $number,
                $numberSort,
                $title,
                $firstLine,
                $author,
                $tune,
                $meter,
                $ccli,
                $copyright
            );
            if ($stmt->execute() === true) {
                $ok++;
            } else {
                $errors[] = "Row $row: database error — skipped.";
            }
        }
        $stmt->close();
        fclose($fh);

        if ($row > self::CSV_MAX_ROWS) {
            $errors[] = 'File exceeded ' . self::CSV_MAX_ROWS . ' rows — remaining rows were not processed.';
        }

        return ['ok' => $ok, 'errors' => $errors];
    }

    // -------------------------------------------------------------------
    // 🔗 Public share token (mirrors AssetRegister's publicToken generator)
    // -------------------------------------------------------------------

    public static function generateShareToken(): string
    {
        return bin2hex(random_bytes(16));
    }

    // -------------------------------------------------------------------
    // 🧰 Internals
    // -------------------------------------------------------------------

    private static function nullableStr(mixed $v, int $maxLen): ?string
    {
        $s = trim((string) ($v ?? ''));
        if ($s === '') {
            return null;
        }
        return mb_substr($s, 0, $maxLen);
    }

    /**
     * True when `$host` is an IP literal that is not an ordinary public
     * address, or resolves (A/AAAA records) to one — the SSRF guard. The
     * range test is `SafeFetch::isPublicIp()` (#514 part P4), shared with
     * the outside-calendar importer.
     *
     * A hostname that fails to resolve at all is treated as NOT reserved
     * (its own connection attempt will simply fail); this function only ever
     * makes the remote client MORE cautious, never determines reachability
     * on its own. That deliberately differs from `SafeFetch::check()`, which
     * refuses a name that does not resolve. The difference is kept because
     * the two situations differ: the hymn lookup's host is a fixed
     * administrator setting, re-checked against the configured base URL on
     * every request, and its fetch never follows a redirect; the calendar
     * importer fetches whatever address was typed in and follows redirects,
     * so it must refuse whatever it cannot vouch for.
     */
    private static function isPrivateOrReservedHost(string $host): bool
    {
        $host = trim($host, "[]"); // strip IPv6 literal brackets if present

        // 🔍 If it's already an IP literal, check it directly.
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return SafeFetch::isPublicIp($host) === false;
        }

        // 🌐 Otherwise resolve and check every returned address — DNS
        // rebinding between this check and the actual curl connect is a
        // documented residual risk on shared hosting with no pinned
        // resolver (see migration 178 header / DEV_NOTES); this is a
        // best-effort belt-and-braces check, not a complete mitigation.
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (is_array($records) === false || count($records) === 0) {
            return false;
        }
        foreach ($records as $rec) {
            $ip = (string) ($rec['ip'] ?? $rec['ipv6'] ?? '');
            if ($ip === '') {
                continue;
            }
            if (SafeFetch::isPublicIp($ip) === false) {
                return true;
            }
        }
        return false;
    }

    /**
     * GET `$url` with every SSRF/size/timeout guard applied, returning the
     * body on a clean 200 JSON-looking response, or null on ANY failure.
     * NEVER logs `$url`'s query string or the Authorization header value.
     *
     * Deliberately unchanged by #514 part P4. What it still does NOT do:
     * pin the connection to the addresses `isPrivateOrReservedHost()`
     * checked, so a name re-pointed between the check and the connection
     * (DNS rebinding) is not caught here; and it does not switch off a
     * proxy set in the server's environment. `SafeFetch::get()` does both.
     * Moving this onto it is a follow-up issue raised by part P11 of #514,
     * not part of P4.
     *
     * @param list<string> $headers
     */
    private static function curlGetCapped(string $url, array $headers): ?string
    {
        if (function_exists('curl_init') === false) {
            return null;
        }
        $ch = curl_init();
        if ($ch === false) {
            return null;
        }

        $capped   = false;
        $buffer   = '';
        $writeFn  = static function ($handle, string $chunk) use (&$buffer, &$capped): int {
            $buffer .= $chunk;
            if (strlen($buffer) > Hymnal::REMOTE_MAX_BYTES) {
                $capped = true;
                return 0; // 🛑 abort the transfer — curl treats a short return as an error
            }
            return strlen($chunk);
        };

        curl_setopt_array($ch, [
            CURLOPT_URL             => $url,
            CURLOPT_HTTPHEADER      => $headers,
            CURLOPT_TIMEOUT         => self::REMOTE_TOTAL_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT  => self::REMOTE_CONNECT_TIMEOUT,
            CURLOPT_FOLLOWLOCATION  => false,
            CURLOPT_PROTOCOLS       => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_WRITEFUNCTION   => $writeFn,
            CURLOPT_SSL_VERIFYPEER  => true,
            CURLOPT_SSL_VERIFYHOST  => 2,
        ]);

        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_errno($ch);
        curl_close($ch);

        if ($capped === true) {
            Logger::errorPlatform('Hymnal', 'Warning', 'REMOTE_OVERSIZE', 'Remote hymnal response exceeded the size cap — aborted', '');
            return null;
        }
        if ($err !== 0 || $code !== 200) {
            // 🔒 Log status/host only — never the query string or headers.
            Logger::errorPlatform('Hymnal', 'Notice', 'REMOTE_UNAVAILABLE', 'Remote hymnal lookup failed (curl errno ' . $err . ', HTTP ' . $code . ')', '');
            return null;
        }
        return $buffer;
    }

    /** @return list<array<string, mixed>>|null */
    private static function cacheGet(int $siteId, string $queryHash, int $ttlSeconds): ?array
    {
        $db = App::db();
        $stmt = $db->prepare(
            'SELECT resultsJson FROM tblHymnLookupCache '
            . 'WHERE siteID = ? AND queryHash = ? AND fetchedAt > (NOW() - INTERVAL ? SECOND) LIMIT 1'
        );
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('isi', $siteId, $queryHash, $ttlSeconds);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row === null) {
            return null;
        }
        $decoded = json_decode((string) $row['resultsJson'], true);
        return is_array($decoded) === true ? $decoded : null;
    }

    /** @param list<array<string, mixed>> $results */
    private static function cachePut(int $siteId, string $queryHash, array $results): void
    {
        $db   = App::db();
        $json = json_encode($results, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return;
        }
        $stmt = $db->prepare(
            'INSERT INTO tblHymnLookupCache (siteID, queryHash, resultsJson, fetchedAt) VALUES (?, ?, ?, NOW()) '
            . 'ON DUPLICATE KEY UPDATE resultsJson = VALUES(resultsJson), fetchedAt = VALUES(fetchedAt)'
        );
        if ($stmt === false) {
            return;
        }
        $stmt->bind_param('iss', $siteId, $queryHash, $json);
        $stmt->execute();
        $stmt->close();
    }
}
