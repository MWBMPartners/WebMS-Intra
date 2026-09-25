<?php
// Path: _core/WindowsTimeZones.php
/**
 * -----------------------------------------------------------------------------
 * Windows time-zone names, turned into ordinary ones 🕰️🪟 (#514, part P5)
 * -----------------------------------------------------------------------------
 * THIS FILE IS GENERATED. Do not edit the list by hand.
 * It is written by `tools/generate-windows-timezones.php`; run that script
 * again to rebuild it, and `--check` to see whether it is still current.
 *
 * Generated on 25 September 2026 from ICU version 78.3
 * (139 Windows names).
 *
 * WHY IT EXISTS
 * -------------
 * A calendar exported from Microsoft Outlook or Microsoft 365 writes its time
 * zones with Windows' own names: `DTSTART;TZID="GMT Standard Time"` where
 * Google would write `DTSTART;TZID=Europe/London`. PHP only understands the
 * ordinary names, so without this list every Microsoft calendar would be read
 * in the wrong zone — and, because "GMT Standard Time" happens to look right
 * in winter, wrong in a way that only shows up for half the year.
 *
 * WHAT IT DOES NOT DO
 * -------------------
 * - It does not cover Windows names invented by one organisation (Outlook can
 *   write `Customized Time Zone` for a zone somebody made up). Those are not
 *   in ICU, so `toIana()` answers null and the caller falls back to the
 *   calendar's own zone and records a warning. That is deliberate: a made-up
 *   name cannot be guessed at safely.
 * - One Windows name covers several countries ("GMT Standard Time" is the
 *   United Kingdom, Ireland, Portugal and others). The answer here is the one
 *   ICU itself calls the default for that name. A calendar file gives us
 *   nothing else to choose with, so there is no better answer available.
 * - It is a lookup list and nothing more. It never reads a calendar, and it
 *   never decides what a time means; `Portal\Core\IcsReader` does that.
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/514
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

final class WindowsTimeZones
{
    /**
     * Windows time-zone name => ordinary (IANA) time-zone name.
     *
     * Generated; see the file header. Sorted by the Windows name so that a
     * change to this list is readable in a diff.
     *
     * @var array<string,string>
     */
    public const MAP = [
        'AUS Central Standard Time' => 'Australia/Darwin',
        'AUS Eastern Standard Time' => 'Australia/Sydney',
        'Afghanistan Standard Time' => 'Asia/Kabul',
        'Alaskan Standard Time' => 'America/Anchorage',
        'Aleutian Standard Time' => 'America/Adak',
        'Altai Standard Time' => 'Asia/Barnaul',
        'Arab Standard Time' => 'Asia/Riyadh',
        'Arabian Standard Time' => 'Asia/Dubai',
        'Arabic Standard Time' => 'Asia/Baghdad',
        'Argentina Standard Time' => 'America/Argentina/Buenos_Aires',
        'Astrakhan Standard Time' => 'Europe/Astrakhan',
        'Atlantic Standard Time' => 'America/Halifax',
        'Aus Central W. Standard Time' => 'Australia/Eucla',
        'Azerbaijan Standard Time' => 'Asia/Baku',
        'Azores Standard Time' => 'Atlantic/Azores',
        'Bahia Standard Time' => 'America/Bahia',
        'Bangladesh Standard Time' => 'Asia/Dhaka',
        'Belarus Standard Time' => 'Europe/Minsk',
        'Bougainville Standard Time' => 'Pacific/Bougainville',
        'Canada Central Standard Time' => 'America/Regina',
        'Cape Verde Standard Time' => 'Atlantic/Cape_Verde',
        'Caucasus Standard Time' => 'Asia/Yerevan',
        'Cen. Australia Standard Time' => 'Australia/Adelaide',
        'Central America Standard Time' => 'America/Guatemala',
        'Central Asia Standard Time' => 'Asia/Bishkek',
        'Central Brazilian Standard Time' => 'America/Cuiaba',
        'Central Europe Standard Time' => 'Europe/Budapest',
        'Central European Standard Time' => 'Europe/Warsaw',
        'Central Pacific Standard Time' => 'Pacific/Guadalcanal',
        'Central Standard Time' => 'America/Chicago',
        'Central Standard Time (Mexico)' => 'America/Mexico_City',
        'Chatham Islands Standard Time' => 'Pacific/Chatham',
        'China Standard Time' => 'Asia/Shanghai',
        'Cuba Standard Time' => 'America/Havana',
        'Dateline Standard Time' => 'Etc/GMT+12',
        'E. Africa Standard Time' => 'Africa/Nairobi',
        'E. Australia Standard Time' => 'Australia/Brisbane',
        'E. Europe Standard Time' => 'Europe/Chisinau',
        'E. South America Standard Time' => 'America/Sao_Paulo',
        'Easter Island Standard Time' => 'Pacific/Easter',
        'Eastern Standard Time' => 'America/New_York',
        'Eastern Standard Time (Mexico)' => 'America/Cancun',
        'Egypt Standard Time' => 'Africa/Cairo',
        'Ekaterinburg Standard Time' => 'Asia/Yekaterinburg',
        'FLE Standard Time' => 'Europe/Kyiv',
        'Fiji Standard Time' => 'Pacific/Fiji',
        'GMT Standard Time' => 'Europe/London',
        'GTB Standard Time' => 'Europe/Bucharest',
        'Georgian Standard Time' => 'Asia/Tbilisi',
        'Greenland Standard Time' => 'America/Nuuk',
        'Greenwich Standard Time' => 'Atlantic/Reykjavik',
        'Haiti Standard Time' => 'America/Port-au-Prince',
        'Hawaiian Standard Time' => 'Pacific/Honolulu',
        'India Standard Time' => 'Asia/Kolkata',
        'Iran Standard Time' => 'Asia/Tehran',
        'Israel Standard Time' => 'Asia/Jerusalem',
        'Jordan Standard Time' => 'Asia/Amman',
        'Kaliningrad Standard Time' => 'Europe/Kaliningrad',
        'Korea Standard Time' => 'Asia/Seoul',
        'Libya Standard Time' => 'Africa/Tripoli',
        'Line Islands Standard Time' => 'Pacific/Kiritimati',
        'Lord Howe Standard Time' => 'Australia/Lord_Howe',
        'Magadan Standard Time' => 'Asia/Magadan',
        'Magallanes Standard Time' => 'America/Punta_Arenas',
        'Marquesas Standard Time' => 'Pacific/Marquesas',
        'Mauritius Standard Time' => 'Indian/Mauritius',
        'Middle East Standard Time' => 'Asia/Beirut',
        'Montevideo Standard Time' => 'America/Montevideo',
        'Morocco Standard Time' => 'Africa/Casablanca',
        'Mountain Standard Time' => 'America/Denver',
        'Mountain Standard Time (Mexico)' => 'America/Mazatlan',
        'Myanmar Standard Time' => 'Asia/Yangon',
        'N. Central Asia Standard Time' => 'Asia/Novosibirsk',
        'Namibia Standard Time' => 'Africa/Windhoek',
        'Nepal Standard Time' => 'Asia/Kathmandu',
        'New Zealand Standard Time' => 'Pacific/Auckland',
        'Newfoundland Standard Time' => 'America/St_Johns',
        'Norfolk Standard Time' => 'Pacific/Norfolk',
        'North Asia East Standard Time' => 'Asia/Irkutsk',
        'North Asia Standard Time' => 'Asia/Krasnoyarsk',
        'North Korea Standard Time' => 'Asia/Pyongyang',
        'Omsk Standard Time' => 'Asia/Omsk',
        'Pacific SA Standard Time' => 'America/Santiago',
        'Pacific Standard Time' => 'America/Los_Angeles',
        'Pacific Standard Time (Mexico)' => 'America/Tijuana',
        'Pakistan Standard Time' => 'Asia/Karachi',
        'Paraguay Standard Time' => 'America/Asuncion',
        'Qyzylorda Standard Time' => 'Asia/Qyzylorda',
        'Romance Standard Time' => 'Europe/Paris',
        'Russia Time Zone 10' => 'Asia/Srednekolymsk',
        'Russia Time Zone 11' => 'Asia/Kamchatka',
        'Russia Time Zone 3' => 'Europe/Samara',
        'Russian Standard Time' => 'Europe/Moscow',
        'SA Eastern Standard Time' => 'America/Cayenne',
        'SA Pacific Standard Time' => 'America/Bogota',
        'SA Western Standard Time' => 'America/La_Paz',
        'SE Asia Standard Time' => 'Asia/Bangkok',
        'Saint Pierre Standard Time' => 'America/Miquelon',
        'Sakhalin Standard Time' => 'Asia/Sakhalin',
        'Samoa Standard Time' => 'Pacific/Apia',
        'Sao Tome Standard Time' => 'Africa/Sao_Tome',
        'Saratov Standard Time' => 'Europe/Saratov',
        'Singapore Standard Time' => 'Asia/Singapore',
        'South Africa Standard Time' => 'Africa/Johannesburg',
        'South Sudan Standard Time' => 'Africa/Juba',
        'Sri Lanka Standard Time' => 'Asia/Colombo',
        'Sudan Standard Time' => 'Africa/Khartoum',
        'Syria Standard Time' => 'Asia/Damascus',
        'Taipei Standard Time' => 'Asia/Taipei',
        'Tasmania Standard Time' => 'Australia/Hobart',
        'Tocantins Standard Time' => 'America/Araguaina',
        'Tokyo Standard Time' => 'Asia/Tokyo',
        'Tomsk Standard Time' => 'Asia/Tomsk',
        'Tonga Standard Time' => 'Pacific/Tongatapu',
        'Transbaikal Standard Time' => 'Asia/Chita',
        'Turkey Standard Time' => 'Europe/Istanbul',
        'Turks And Caicos Standard Time' => 'America/Grand_Turk',
        'US Eastern Standard Time' => 'America/Indiana/Indianapolis',
        'US Mountain Standard Time' => 'America/Phoenix',
        'UTC' => 'Etc/UTC',
        'UTC+12' => 'Etc/GMT-12',
        'UTC+13' => 'Etc/GMT-13',
        'UTC-02' => 'Etc/GMT+2',
        'UTC-08' => 'Etc/GMT+8',
        'UTC-09' => 'Etc/GMT+9',
        'UTC-11' => 'Etc/GMT+11',
        'Ulaanbaatar Standard Time' => 'Asia/Ulaanbaatar',
        'Venezuela Standard Time' => 'America/Caracas',
        'Vladivostok Standard Time' => 'Asia/Vladivostok',
        'Volgograd Standard Time' => 'Europe/Volgograd',
        'W. Australia Standard Time' => 'Australia/Perth',
        'W. Central Africa Standard Time' => 'Africa/Lagos',
        'W. Europe Standard Time' => 'Europe/Berlin',
        'W. Mongolia Standard Time' => 'Asia/Hovd',
        'West Asia Standard Time' => 'Asia/Tashkent',
        'West Bank Standard Time' => 'Asia/Hebron',
        'West Pacific Standard Time' => 'Pacific/Port_Moresby',
        'Yakutsk Standard Time' => 'Asia/Yakutsk',
        'Yukon Standard Time' => 'America/Whitehorse',
    ];

    /**
     * Turn a Windows time-zone name into the ordinary name PHP understands.
     *
     * Returns null when the name is not a Windows name we know. The caller
     * must then do something safe — the calendar reader falls back to the
     * calendar's own zone and records a warning — and must NOT treat null as
     * "UTC", which would move every event in that calendar by the offset of
     * whatever zone was really meant.
     *
     * Matching is done first exactly, then ignoring capital letters. Microsoft
     * writes these names in a fixed spelling, but a file that has been through
     * another program on the way can arrive with different capitals, and
     * refusing it for that alone would help nobody.
     *
     * What this cannot do: it cannot recognise a name that is not in the list
     * at all (see the file header), and it does not accept an ordinary IANA
     * name — pass those straight to DateTimeZone instead.
     */
    public static function toIana(string $windowsId): ?string
    {
        $name = trim($windowsId);
        if ($name === '') {
            return null;
        }

        if (isset(self::MAP[$name]) === true) {
            return self::MAP[$name];
        }

        // Built once per request, and only when an exact match failed, so the
        // ordinary case costs nothing.
        static $lowerIndex = null;
        if ($lowerIndex === null) {
            $lowerIndex = [];
            foreach (self::MAP as $windowsName => $ianaId) {
                $lowerIndex[strtolower($windowsName)] = $ianaId;
            }
        }

        return $lowerIndex[strtolower($name)] ?? null;
    }
}
