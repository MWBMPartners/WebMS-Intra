<?php
// Path: _core/Countries.php
/**
 * -----------------------------------------------------------------------------
 * Countries: the ONE list of countries, and whose country is whose 🌍 (#942)
 * -----------------------------------------------------------------------------
 * The owner's decision D-133 (11 November 2026): nothing defaults to Great
 * Britain. The installer ASKS for the installation's country, each
 * organisation may choose its own starting from the installation's, and
 * values that follow from a country (a time zone) are pre-selected from it
 * and stay changeable.
 *
 * WHAT THIS CLASS ANSWERS
 *
 *   choices(), isKnown(), name()  the list of countries the portal offers, as
 *                                 two-letter ISO 3166 codes with English names.
 *   zonesOf(), onlyZone(),        which time zones are in a country, and which
 *   ofZone(), zoneMap()           country a zone is in. From PHP's own zone
 *                                 data (the IANA "zone.tab" that ships with
 *                                 PHP), so nothing is looked up anywhere.
 *   ofInstallation(), ownOf(),    the installation's country, an organisation's
 *   ofOrganisation()              OWN country, and the country an organisation
 *                                 is in (its own, else the installation's).
 *   installationStoredText(),     what the installation's and an organisation's
 *   ownStoredText()               rows HOLD, for the one page that changes them.
 *   lockedInGeneralEditor()       the general Settings editor refuses the key
 *                                 (#967): Admin, Settings, Organisation is the
 *                                 ONE place a country is changed, at either level.
 *
 * WHERE THE LIST COMES FROM, AND WHY IT IS WRITTEN OUT HERE
 *
 * The codes are every country PHP's zone data names a zone for (247 on PHP
 * 8.5.12 with zone data 2026.5). The names were generated once from ICU, the
 * library behind PHP's "intl" extension (`Locale::getDisplayRegion()`), and
 * are written out below rather than asked of intl at run time, because intl
 * is NOT one of the extensions the installer requires and a customer's
 * hosting may not have it. A list that cannot be read on some hosting is not
 * a list. The cost: the names are in English only, which matters on an
 * administrator's page and nowhere a member reads.
 *
 * WHY THE INSTALLER CAN LOAD THIS FILE
 *
 * The installer has NO autoloader (#728): it requires every class by hand.
 * Everything here except the three "of…" methods that read the database uses
 * nothing but PHP itself, so `require_once` of this one file is enough. The
 * installer must never call ofInstallation(), ownOf(), ofOrganisation() or
 * the two "…StoredText()" methods: they reach `App`, which the installer does
 * not load.
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/942
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

final class Countries
{
    /** The setting that holds a country: installation-wide, and per organisation (#677). */
    public const SETTING = 'org.address.countryCode';

    /**
     * 🌍 Every country the portal offers: two-letter code => English name.
     * Sorted by code. See the file header for where it came from.
     *
     * @var array<string, string>
     */
    private const NAMES = [
        'AD' => 'Andorra',
        'AE' => 'United Arab Emirates',
        'AF' => 'Afghanistan',
        'AG' => 'Antigua & Barbuda',
        'AI' => 'Anguilla',
        'AL' => 'Albania',
        'AM' => 'Armenia',
        'AO' => 'Angola',
        'AQ' => 'Antarctica',
        'AR' => 'Argentina',
        'AS' => 'American Samoa',
        'AT' => 'Austria',
        'AU' => 'Australia',
        'AW' => 'Aruba',
        'AX' => 'Åland Islands',
        'AZ' => 'Azerbaijan',
        'BA' => 'Bosnia & Herzegovina',
        'BB' => 'Barbados',
        'BD' => 'Bangladesh',
        'BE' => 'Belgium',
        'BF' => 'Burkina Faso',
        'BG' => 'Bulgaria',
        'BH' => 'Bahrain',
        'BI' => 'Burundi',
        'BJ' => 'Benin',
        'BL' => 'St. Barthélemy',
        'BM' => 'Bermuda',
        'BN' => 'Brunei',
        'BO' => 'Bolivia',
        'BQ' => 'Caribbean Netherlands',
        'BR' => 'Brazil',
        'BS' => 'Bahamas',
        'BT' => 'Bhutan',
        'BW' => 'Botswana',
        'BY' => 'Belarus',
        'BZ' => 'Belize',
        'CA' => 'Canada',
        'CC' => 'Cocos (Keeling) Islands',
        'CD' => 'Congo - Kinshasa',
        'CF' => 'Central African Republic',
        'CG' => 'Congo - Brazzaville',
        'CH' => 'Switzerland',
        'CI' => 'Côte d’Ivoire',
        'CK' => 'Cook Islands',
        'CL' => 'Chile',
        'CM' => 'Cameroon',
        'CN' => 'China',
        'CO' => 'Colombia',
        'CR' => 'Costa Rica',
        'CU' => 'Cuba',
        'CV' => 'Cape Verde',
        'CW' => 'Curaçao',
        'CX' => 'Christmas Island',
        'CY' => 'Cyprus',
        'CZ' => 'Czechia',
        'DE' => 'Germany',
        'DJ' => 'Djibouti',
        'DK' => 'Denmark',
        'DM' => 'Dominica',
        'DO' => 'Dominican Republic',
        'DZ' => 'Algeria',
        'EC' => 'Ecuador',
        'EE' => 'Estonia',
        'EG' => 'Egypt',
        'EH' => 'Western Sahara',
        'ER' => 'Eritrea',
        'ES' => 'Spain',
        'ET' => 'Ethiopia',
        'FI' => 'Finland',
        'FJ' => 'Fiji',
        'FK' => 'Falkland Islands',
        'FM' => 'Micronesia',
        'FO' => 'Faroe Islands',
        'FR' => 'France',
        'GA' => 'Gabon',
        'GB' => 'United Kingdom',
        'GD' => 'Grenada',
        'GE' => 'Georgia',
        'GF' => 'French Guiana',
        'GG' => 'Guernsey',
        'GH' => 'Ghana',
        'GI' => 'Gibraltar',
        'GL' => 'Greenland',
        'GM' => 'Gambia',
        'GN' => 'Guinea',
        'GP' => 'Guadeloupe',
        'GQ' => 'Equatorial Guinea',
        'GR' => 'Greece',
        'GS' => 'South Georgia & South Sandwich Islands',
        'GT' => 'Guatemala',
        'GU' => 'Guam',
        'GW' => 'Guinea-Bissau',
        'GY' => 'Guyana',
        'HK' => 'Hong Kong SAR China',
        'HN' => 'Honduras',
        'HR' => 'Croatia',
        'HT' => 'Haiti',
        'HU' => 'Hungary',
        'ID' => 'Indonesia',
        'IE' => 'Ireland',
        'IL' => 'Israel',
        'IM' => 'Isle of Man',
        'IN' => 'India',
        'IO' => 'British Indian Ocean Territory',
        'IQ' => 'Iraq',
        'IR' => 'Iran',
        'IS' => 'Iceland',
        'IT' => 'Italy',
        'JE' => 'Jersey',
        'JM' => 'Jamaica',
        'JO' => 'Jordan',
        'JP' => 'Japan',
        'KE' => 'Kenya',
        'KG' => 'Kyrgyzstan',
        'KH' => 'Cambodia',
        'KI' => 'Kiribati',
        'KM' => 'Comoros',
        'KN' => 'St. Kitts & Nevis',
        'KP' => 'North Korea',
        'KR' => 'South Korea',
        'KW' => 'Kuwait',
        'KY' => 'Cayman Islands',
        'KZ' => 'Kazakhstan',
        'LA' => 'Laos',
        'LB' => 'Lebanon',
        'LC' => 'St. Lucia',
        'LI' => 'Liechtenstein',
        'LK' => 'Sri Lanka',
        'LR' => 'Liberia',
        'LS' => 'Lesotho',
        'LT' => 'Lithuania',
        'LU' => 'Luxembourg',
        'LV' => 'Latvia',
        'LY' => 'Libya',
        'MA' => 'Morocco',
        'MC' => 'Monaco',
        'MD' => 'Moldova',
        'ME' => 'Montenegro',
        'MF' => 'St. Martin',
        'MG' => 'Madagascar',
        'MH' => 'Marshall Islands',
        'MK' => 'North Macedonia',
        'ML' => 'Mali',
        'MM' => 'Myanmar (Burma)',
        'MN' => 'Mongolia',
        'MO' => 'Macao SAR China',
        'MP' => 'Northern Mariana Islands',
        'MQ' => 'Martinique',
        'MR' => 'Mauritania',
        'MS' => 'Montserrat',
        'MT' => 'Malta',
        'MU' => 'Mauritius',
        'MV' => 'Maldives',
        'MW' => 'Malawi',
        'MX' => 'Mexico',
        'MY' => 'Malaysia',
        'MZ' => 'Mozambique',
        'NA' => 'Namibia',
        'NC' => 'New Caledonia',
        'NE' => 'Niger',
        'NF' => 'Norfolk Island',
        'NG' => 'Nigeria',
        'NI' => 'Nicaragua',
        'NL' => 'Netherlands',
        'NO' => 'Norway',
        'NP' => 'Nepal',
        'NR' => 'Nauru',
        'NU' => 'Niue',
        'NZ' => 'New Zealand',
        'OM' => 'Oman',
        'PA' => 'Panama',
        'PE' => 'Peru',
        'PF' => 'French Polynesia',
        'PG' => 'Papua New Guinea',
        'PH' => 'Philippines',
        'PK' => 'Pakistan',
        'PL' => 'Poland',
        'PM' => 'St. Pierre & Miquelon',
        'PN' => 'Pitcairn Islands',
        'PR' => 'Puerto Rico',
        'PS' => 'Palestinian Territories',
        'PT' => 'Portugal',
        'PW' => 'Palau',
        'PY' => 'Paraguay',
        'QA' => 'Qatar',
        'RE' => 'Réunion',
        'RO' => 'Romania',
        'RS' => 'Serbia',
        'RU' => 'Russia',
        'RW' => 'Rwanda',
        'SA' => 'Saudi Arabia',
        'SB' => 'Solomon Islands',
        'SC' => 'Seychelles',
        'SD' => 'Sudan',
        'SE' => 'Sweden',
        'SG' => 'Singapore',
        'SH' => 'St. Helena, Ascension & Tristan da Cunha',
        'SI' => 'Slovenia',
        'SJ' => 'Svalbard & Jan Mayen',
        'SK' => 'Slovakia',
        'SL' => 'Sierra Leone',
        'SM' => 'San Marino',
        'SN' => 'Senegal',
        'SO' => 'Somalia',
        'SR' => 'Suriname',
        'SS' => 'South Sudan',
        'ST' => 'São Tomé & Príncipe',
        'SV' => 'El Salvador',
        'SX' => 'Sint Maarten',
        'SY' => 'Syria',
        'SZ' => 'Eswatini',
        'TC' => 'Turks & Caicos Islands',
        'TD' => 'Chad',
        'TF' => 'French Southern and Antarctic Lands',
        'TG' => 'Togo',
        'TH' => 'Thailand',
        'TJ' => 'Tajikistan',
        'TK' => 'Tokelau',
        'TL' => 'Timor-Leste',
        'TM' => 'Turkmenistan',
        'TN' => 'Tunisia',
        'TO' => 'Tonga',
        'TR' => 'Türkiye',
        'TT' => 'Trinidad & Tobago',
        'TV' => 'Tuvalu',
        'TW' => 'Taiwan',
        'TZ' => 'Tanzania',
        'UA' => 'Ukraine',
        'UG' => 'Uganda',
        'UM' => 'U.S. Outlying Islands',
        'US' => 'United States',
        'UY' => 'Uruguay',
        'UZ' => 'Uzbekistan',
        'VA' => 'Vatican City',
        'VC' => 'St. Vincent & Grenadines',
        'VE' => 'Venezuela',
        'VG' => 'British Virgin Islands',
        'VI' => 'U.S. Virgin Islands',
        'VN' => 'Vietnam',
        'VU' => 'Vanuatu',
        'WF' => 'Wallis & Futuna',
        'WS' => 'Samoa',
        'YE' => 'Yemen',
        'YT' => 'Mayotte',
        'ZA' => 'South Africa',
        'ZM' => 'Zambia',
        'ZW' => 'Zimbabwe',
    ];

    /** 📋 The whole list, code => name, sorted by NAME for a drop-down. @return array<string, string> */
    public static function choices(): array
    {
        $list = self::NAMES;
        asort($list, SORT_STRING | SORT_FLAG_CASE);

        return $list;
    }

    /** ✅ Is this one of the countries the portal offers? Capitals only: callers tidy first. */
    public static function isKnown(string $code): bool
    {
        return isset(self::NAMES[$code]);
    }

    /** 🏷️ The country's English name, or the text itself when it is not on the list. */
    public static function name(string $code): string
    {
        return self::NAMES[$code] ?? $code;
    }

    /**
     * 🧹 A posted country, tidied: '' when nothing was chosen, the code in
     * capitals when it is one the portal offers, and null for anything else,
     * which the caller REFUSES. Never a made-up country: an empty box stays
     * empty (D-133).
     */
    public static function fromPosted(mixed $posted): ?string
    {
        if (is_string($posted) === false) {
            return null;
        }
        $code = strtoupper(trim($posted));
        if ($code === '') {
            return '';
        }

        return self::isKnown($code) === true ? $code : null;
    }

    /**
     * 🕰️ The time zones PHP's zone data places in a country, in PHP's order
     * (alphabetical). Empty for a code it does not know.
     *
     * @return list<string>
     */
    public static function zonesOf(string $code): array
    {
        if (preg_match('/^[A-Z]{2}$/', $code) !== 1) {
            return [];
        }
        try {
            return array_values(\DateTimeZone::listIdentifiers(\DateTimeZone::PER_COUNTRY, $code));
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * 🕰️ The ONE zone of a country that has exactly one (GB: Europe/London),
     * else ''. A country with several zones does not decide the zone: the
     * United States starts at America/Adak alphabetically, and pre-selecting
     * that for somebody in New York would be worse than asking. So a page
     * asks instead, unless the person's own browser zone is in the country.
     */
    public static function onlyZone(string $code): string
    {
        $zones = self::zonesOf($code);

        return count($zones) === 1 ? $zones[0] : '';
    }

    /** 🗺️ The country a zone is in, from PHP's zone data, or '' (UTC is in no country). */
    public static function ofZone(string $zone): string
    {
        try {
            $where = (new \DateTimeZone($zone))->getLocation();
        } catch (\Throwable $e) {
            return '';
        }
        $code = is_array($where) === true ? (string) ($where['country_code'] ?? '') : '';

        return self::isKnown($code) === true ? $code : '';
    }

    /**
     * 🗺️ Every offered country => its zones, for a page's script to pre-select
     * a zone from a country without asking the server again.
     *
     * @return array<string, list<string>>
     */
    public static function zoneMap(): array
    {
        $map = [];
        foreach (array_keys(self::NAMES) as $code) {
            $map[$code] = self::zonesOf($code);
        }

        return $map;
    }

    /**
     * 🏢 The INSTALLATION's country (its own row, `siteID IS NULL`), or ''.
     * Read from the database, not from the merged settings, because the
     * merged value is the open organisation's when it has its own (#674).
     */
    public static function ofInstallation(): string
    {
        return self::readRows(null)['installation'];
    }

    /**
     * 🏠 An organisation's OWN country (its own row), or '' when it has not
     * chosen one and follows the installation's. What its settings page puts
     * in the box, so a Save does not copy the installation's country into the
     * organisation's own row (#674, #677).
     */
    public static function ownOf(int $siteId): string
    {
        return self::readRows($siteId)['own'];
    }

    /**
     * 🏠 What an organisation's own row HOLDS, trimmed ('' when it has no row),
     * for the organisation settings page and its save handler only.
     *
     * Two letters in any case come back in CAPITALS, because that is how
     * every other reader takes them: a row holding "fr" IS France to
     * ofOrganisation(), so the page must offer France, not "fr (not on the
     * portal's list)" (round 3 of #942's check found exactly that label).
     * Anything else comes back as stored. A row holding text that is not a
     * two-letter code (say "GBR", typed into the general Settings editor)
     * counts as "not chosen" everywhere else, so the page must SAY so and
     * offer that text back, rather than show "Same as the installation" —
     * which the next Save would act on by deleting the row, with nobody told
     * — and the save handler accepts it back unchanged.
     */
    public static function ownStoredText(int $siteId): string
    {
        $text = self::readRows($siteId)['ownText'];

        return preg_match('/^[A-Za-z]{2}$/', $text) === 1 ? strtoupper($text) : $text;
    }

    /**
     * 🏢 What the INSTALLATION's own row HOLDS, trimmed ('' when it has no row
     * or an empty one), for the installation card of the organisation settings
     * page and its save handler only (#967).
     *
     * Same rule as ownStoredText(): two letters in any case come back in
     * CAPITALS, because ofInstallation() reads them that way; anything else
     * comes back as stored. WHY THE PAGE NEEDS THE RAW TEXT: before #967 the
     * general Settings editor could store text that is not a country code
     * (say "GBR") as the installation's country, and ofInstallation() then
     * answers '' — no country — with nothing anywhere to say why (round 3 of
     * #942's check). The card has to SHOW that text, offer it back selected
     * (a select with no selected option is a vote for its first option, #763,
     * here "No country chosen"), and say plainly that the portal ignores it.
     */
    public static function installationStoredText(): string
    {
        $text = self::readRows(null)['installationText'];

        return preg_match('/^[A-Za-z]{2}$/', $text) === 1 ? strtoupper($text) : $text;
    }

    /**
     * 🔒 Does the general Settings editor refuse this key? Yes for the country
     * (#967). That editor stores ANY text, at either level, where the
     * organisation settings page accepts only a country on the portal's list
     * (fromPosted()). Before this, the editor was the only way to change the
     * INSTALLATION's country after installation, so a typo there ("GBR",
     * "United Kingdom") quietly became "no country" for every organisation
     * following the installation, with nothing on any page to say so. The
     * organisation settings page is now the ONE place a country is changed:
     * an organisation's own by any of its administrators, the installation's
     * by a global administrator. Capitals are ignored, as a key's are
     * everywhere (the database compares names that way).
     */
    public static function lockedInGeneralEditor(string $key): bool
    {
        return strcasecmp($key, self::SETTING) === 0;
    }

    /** What the general settings editor says when it refuses the country. */
    public const LOCKED_MESSAGE = 'The country is changed at Admin, Settings, Organisation, which accepts only a country '
        . 'on the portal\'s list. Nothing was changed.';

    /**
     * 🌍 The country an organisation IS in: its own when it has chosen one,
     * else the installation's, else '' (none known). The ONE answer for every
     * reader that needs "this organisation's country": a new venue
     * (Venues::organisationCountry()), the shared address form's starting
     * value, and the organisation settings page.
     *
     * An organisation row holding anything that is not two capital letters,
     * the empty string included, counts as "not chosen" and the
     * installation's country is used. The settings page never stores such a
     * row (an empty box removes it), so one exists only if somebody wrote it
     * by hand; treating it as a choice of "no country" would let one stray
     * row hide the installation's country without anybody seeing why.
     */
    public static function ofOrganisation(int $siteId): string
    {
        $rows = self::readRows($siteId);

        return $rows['own'] !== '' ? $rows['own'] : $rows['installation'];
    }

    /**
     * Both rows in one query. A read that fails is "nothing known" (''),
     * never a made-up country.
     *
     * @return array{own: string, installation: string, ownText: string, installationText: string}
     */
    private static function readRows(?int $siteId): array
    {
        $answer = ['own' => '', 'installation' => '', 'ownText' => '', 'installationText' => ''];
        try {
            $db   = App::db();
            $site = $siteId ?? 0;
            $key  = self::SETTING;
            $stmt = $db->prepare(
                'SELECT `siteID`, `settingValue` FROM `tblSettings` '
                . 'WHERE `settingKey` = ? AND (`siteID` IS NULL OR `siteID` = ?)'
            );
            if ($stmt === false) {
                return $answer;
            }
            $stmt->bind_param('si', $key, $site);
            $stmt->execute();
            $result = $stmt->get_result();
            while (($row = $result->fetch_assoc()) !== null) {
                $code  = strtoupper(trim((string) ($row['settingValue'] ?? '')));
                $which = $row['siteID'] === null ? 'installation' : 'own';
                $answer[$which] = preg_match('/^[A-Z]{2}$/', $code) === 1 ? $code : '';
                // The raw text as well, for the one page that has to SAY when it
                // is not a country (ownStoredText(), installationStoredText()).
                $answer[$which . 'Text'] = trim((string) ($row['settingValue'] ?? ''));
            }
            $stmt->close();
        } catch (\Throwable $e) {
            return ['own' => '', 'installation' => '', 'ownText' => '', 'installationText' => ''];
        }

        return $answer;
    }
}
