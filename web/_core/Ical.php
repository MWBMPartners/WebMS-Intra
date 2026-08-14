<?php
// Path: _core/Ical.php
/**
 * -----------------------------------------------------------------------------
 * WebMS Intra — iCalendar emitter 🗓️
 * -----------------------------------------------------------------------------
 * Minimal RFC 5545-compliant iCalendar emitter for portal calendars.
 *
 * Why we don't vendor sabre/vobject: the use case here is read-only export
 * of a small handful of event types. ~100 LOC of focused emission covers
 * everything calendar clients (Google, Apple, Outlook) need. Vendor a
 * library if/when bidirectional CalDAV becomes a requirement.
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/271
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

class Ical
{
    /**
     * Build a VCALENDAR string from a flat list of event arrays.
     *
     * @param array<int, array{
     *     uid:string, summary:string, description?:string, location?:string,
     *     startsAt:string, endsAt?:string, allDay?:bool, timezone?:string,
     *     sequence?:int, lastModified?:string, url?:string, rrule?:string,
     *     geo?:array{lat:float|string, lng:float|string}, status?:string
     * }> $events
     *
     * geo/status (#338 residual — calendar/export.php) are optional and
     * additive: callers that don't set them (feed.php, account-feed.php)
     * see byte-identical output to before these keys existed.
     */
    public static function emit(string $calendarName, array $events, string $timezone = 'Europe/London'): string
    {
        $lines = [];
        $lines[] = 'BEGIN:VCALENDAR';
        $lines[] = 'VERSION:2.0';
        $lines[] = 'PRODID:-//MWBM Partners//WebMS-Intra//EN';
        $lines[] = 'CALSCALE:GREGORIAN';
        $lines[] = 'METHOD:PUBLISH';
        $lines[] = 'X-WR-CALNAME:' . self::escapeText($calendarName);
        $lines[] = 'X-WR-TIMEZONE:' . self::escapeText($timezone);

        // 🌍 VTIMEZONE block (#338) — RFC 5545 §3.6.5. Emit a minimal but
        //     correct VTIMEZONE so clients (Apple Calendar, Outlook, Google)
        //     can render local times. Without this, our feed forces UTC
        //     ("Z") timestamps and clients misrepresent local-time events.
        //     We emit STANDARD + DAYLIGHT for the next year using PHP's
        //     DateTimeZone transitions so DST is correctly modelled.
        $lines = array_merge($lines, self::vtimezoneBlock($timezone));

        foreach ($events as $e) {
            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:' . self::escapeText((string) $e['uid']);
            $lines[] = 'DTSTAMP:' . gmdate('Ymd\THis\Z');
            $startsAt = (string) $e['startsAt'];
            $allDay = ($e['allDay'] ?? false) === true;
            $eventTz = (string) ($e['timezone'] ?? $timezone);
            if ($allDay === true) {
                $lines[] = 'DTSTART;VALUE=DATE:' . gmdate('Ymd', strtotime($startsAt));
                if (isset($e['endsAt']) === true && $e['endsAt'] !== null && $e['endsAt'] !== '') {
                    $lines[] = 'DTEND;VALUE=DATE:' . gmdate('Ymd', strtotime((string) $e['endsAt']) + 86400);
                }
            } else {
                // 🕐 Emit with TZID so the time renders in the right zone
                //     instead of "floating" — RFC 5545 §3.3.5.
                $lines[] = 'DTSTART;TZID=' . self::escapeText($eventTz) . ':' . self::localTime($startsAt, $eventTz);
                if (isset($e['endsAt']) === true && $e['endsAt'] !== null && $e['endsAt'] !== '') {
                    $lines[] = 'DTEND;TZID=' . self::escapeText($eventTz) . ':' . self::localTime((string) $e['endsAt'], $eventTz);
                }
            }
            $lines[] = 'SUMMARY:' . self::escapeText((string) $e['summary']);
            if (isset($e['description']) === true && $e['description'] !== null && $e['description'] !== '') {
                $lines[] = 'DESCRIPTION:' . self::escapeText((string) $e['description']);
            }
            if (isset($e['location']) === true && $e['location'] !== null && $e['location'] !== '') {
                $lines[] = 'LOCATION:' . self::escapeText((string) $e['location']);
            }
            // 🌐 GEO (#338 residual — calendar/export.php) — optional lat/lng
            //     pair, RFC 5545 §3.8.1.6. Not exercised by feed.php.
            if (isset($e['geo']) === true && is_array($e['geo']) === true
                && isset($e['geo']['lat']) === true && isset($e['geo']['lng']) === true) {
                $lines[] = 'GEO:' . (string) $e['geo']['lat'] . ';' . (string) $e['geo']['lng'];
            }
            if (isset($e['url']) === true && $e['url'] !== null && $e['url'] !== '') {
                $lines[] = 'URL:' . (string) $e['url'];
            }
            // 🚦 STATUS (#338 residual — calendar/export.php) — CONFIRMED /
            //     CANCELLED / TENTATIVE, RFC 5545 §3.8.1.11. Not exercised
            //     by feed.php.
            if (isset($e['status']) === true && $e['status'] !== null && $e['status'] !== '') {
                $lines[] = 'STATUS:' . self::escapeText((string) $e['status']);
            }
            // 🔁 RRULE emission (#338) — when the caller provides recurrence
            //     metadata (mapped from tblRecurrenceRules by the caller —
            //     see calendar/export.php), emit a real RRULE instead of
            //     expanding occurrences upstream. Mirrors RFC 5545 §3.8.5.3
            //     patterns: FREQ + (INTERVAL) + (BYDAY) + (UNTIL|COUNT).
            if (isset($e['rrule']) === true && $e['rrule'] !== null && $e['rrule'] !== '') {
                $lines[] = 'RRULE:' . (string) $e['rrule'];
            }
            $lines[] = 'SEQUENCE:' . (int) ($e['sequence'] ?? 0);
            if (isset($e['lastModified']) === true && $e['lastModified'] !== null) {
                $lines[] = 'LAST-MODIFIED:' . gmdate('Ymd\THis\Z', strtotime((string) $e['lastModified']));
            }
            $lines[] = 'END:VEVENT';
        }

        $lines[] = 'END:VCALENDAR';
        return implode("\r\n", array_map([self::class, 'fold'], $lines)) . "\r\n";
    }

    /**
     * Build a minimal VTIMEZONE block for the given IANA timezone (#338).
     * Walks the next 24 months of DST transitions to emit STANDARD + DAYLIGHT
     * components. Returns a string array of lines.
     *
     * @return array<int, string>
     */
    private static function vtimezoneBlock(string $tz): array
    {
        try {
            $zone = new \DateTimeZone($tz);
        } catch (\Exception $e) {
            return [];
        }
        $lines = ['BEGIN:VTIMEZONE', 'TZID:' . self::escapeText($tz)];

        $now = time();
        $end = $now + (86400 * 365 * 2);
        $transitions = $zone->getTransitions($now - 86400 * 30, $end) ?: [];
        if (count($transitions) < 2) {
            $lines[] = 'BEGIN:STANDARD';
            $lines[] = 'DTSTART:19700101T000000';
            $lines[] = 'TZOFFSETFROM:+0000';
            $lines[] = 'TZOFFSETTO:+0000';
            $lines[] = 'END:STANDARD';
            $lines[] = 'END:VTIMEZONE';
            return $lines;
        }
        $prev = $transitions[0];
        for ($i = 1, $n = count($transitions); $i < $n; $i++) {
            $t = $transitions[$i];
            $comp = $t['isdst'] === true ? 'DAYLIGHT' : 'STANDARD';
            $lines[] = 'BEGIN:' . $comp;
            $lines[] = 'DTSTART:' . gmdate('Ymd\THis', (int) $t['ts']);
            $lines[] = 'TZOFFSETFROM:' . self::offsetToHHMM((int) $prev['offset']);
            $lines[] = 'TZOFFSETTO:'   . self::offsetToHHMM((int) $t['offset']);
            $lines[] = 'TZNAME:' . (string) $t['abbr'];
            $lines[] = 'END:' . $comp;
            $prev = $t;
        }
        $lines[] = 'END:VTIMEZONE';
        return $lines;
    }

    private static function offsetToHHMM(int $offsetSec): string
    {
        $sign  = $offsetSec < 0 ? '-' : '+';
        $abs   = abs($offsetSec);
        $hh    = (int) floor($abs / 3600);
        $mm    = (int) floor(($abs % 3600) / 60);
        return $sign . str_pad((string) $hh, 2, '0', STR_PAD_LEFT) . str_pad((string) $mm, 2, '0', STR_PAD_LEFT);
    }

    private static function localTime(string $datetime, string $tz): string
    {
        try {
            $dt = new \DateTimeImmutable($datetime, new \DateTimeZone('UTC'));
            return $dt->setTimezone(new \DateTimeZone($tz))->format('Ymd\THis');
        } catch (\Exception $e) {
            return gmdate('Ymd\THis', strtotime($datetime));
        }
    }

    /**
     * Generate or retrieve the per-user calendar token (RFC 4226-style
     * random 32-byte hex, stored hashed in tblUsers).
     *
     * @return string The plaintext token (returned ONLY at generation;
     *                from then on only the hash is retained).
     */
    public static function ensureUserToken(int $userId): string
    {
        $db = App::db();
        $stmt = $db->prepare('SELECT calendarToken FROM tblUsers WHERE userID = ? LIMIT 1');
        if ($stmt === false) {
            throw new \RuntimeException('Could not load user');
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row !== null && $row['calendarToken'] !== null && $row['calendarToken'] !== '') {
            // 🪞 Token exists but we don't have the plaintext anymore (it's
            //    hashed). Regenerate so the user gets a fresh URL.
        }

        $plaintext = bin2hex(random_bytes(32));
        $hash      = hash('sha256', $plaintext);
        $stmt = $db->prepare('UPDATE tblUsers SET calendarToken = ? WHERE userID = ?');
        if ($stmt === false) {
            throw new \RuntimeException('Could not save token');
        }
        $stmt->bind_param('si', $hash, $userId);
        $stmt->execute();
        $stmt->close();
        return $plaintext;
    }

    /**
     * Resolve a plaintext token to a user ID. Returns 0 on no match.
     */
    public static function userIdForToken(string $plaintext): int
    {
        if (preg_match('/^[a-f0-9]{64}$/i', $plaintext) !== 1) {
            return 0;
        }
        $hash = hash('sha256', $plaintext);
        $db = App::db();
        $stmt = $db->prepare('SELECT userID FROM tblUsers WHERE calendarToken = ? LIMIT 1');
        if ($stmt === false) {
            return 0;
        }
        $stmt->bind_param('s', $hash);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (int) ($row['userID'] ?? 0);
    }

    private static function escapeText(string $text): string
    {
        // RFC 5545 §3.3.11: backslash, comma, semicolon, newline escaping.
        $text = str_replace(['\\', "\r\n", "\n", "\r", ',', ';'], ['\\\\', '\\n', '\\n', '\\n', '\\,', '\\;'], $text);
        return $text;
    }

    /**
     * RFC 5545 §3.1: lines > 75 octets must be folded with CRLF + single
     * leading whitespace on continuation lines.
     */
    private static function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }
        $folded = substr($line, 0, 75);
        $rest = substr($line, 75);
        while (strlen($rest) > 74) {
            $folded .= "\r\n " . substr($rest, 0, 74);
            $rest = substr($rest, 74);
        }
        if ($rest !== '') {
            $folded .= "\r\n " . $rest;
        }
        return $folded;
    }
}
