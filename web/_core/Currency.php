<?php
// Path: _core/Currency.php
/**
 * -----------------------------------------------------------------------------
 * Currency: the ONE answer to "what money is this organisation in?" 💷 (#966)
 * -----------------------------------------------------------------------------
 * The owner's decision D-133 (11 November 2026): nothing is built in as Great
 * Britain, and values that follow from a country, currency named, are
 * pre-selected from the chosen country at installation and organisation level
 * and stay changeable. Until #966 the portal assumed pounds sterling in 45 PHP
 * files and 9 column defaults, and printed "£" in 48 more.
 *
 * WHAT THIS CLASS ANSWERS
 *
 *   ofCountry(), suggestFor(),    the currency a country uses, and the one the
 *   countryMap()                  portal suggests for it (only a currency it
 *                                 can store: see "hundredths" below).
 *   isChoosable(), choices(),     which currencies may be chosen, and a posted
 *   fromPosted()                  choice tidied or refused.
 *   symbol(), prefix(), label(),  how an amount is written: "£12.50",
 *   format(), formatMajor()       "CHF 12.50", or a plain "12.50" when no
 *                                 currency is known. NEVER "£" by default.
 *   ofInstallation(), ownOf(),    the installation's currency, an organisation's
 *   ownStoredText(),              OWN choice, and the currency an organisation
 *   ofOrganisation(), ofSite()    is in (its own, else the installation's, else
 *                                 none) — exactly as Countries does for the
 *                                 country (#942).
 *   whyCannotChange()             whether an organisation's currency may change
 *                                 now (see "money already recorded" below).
 *
 * ONE CURRENCY PER ORGANISATION, NOT ONE PER APP
 *
 * There used to be four installation-wide settings — `giving.currency`,
 * `projects.currency`, `payments.currency` and `venues.currency` — and they
 * could disagree inside one organisation: a project was shown in its own
 * currency while the pledge against it was paid in `payments.currency`, and
 * the gift that payment created was stored in the payment's currency while
 * every giving total was labelled with `giving.currency`. None of the four
 * could be set for one organisation either (the general Settings editor
 * refuses an organisation row for a key the installation already holds), so
 * none could honour D-133's organisation level. They are gone; this setting,
 * `org.currency`, is the one answer, with an installation row and an
 * organisation row like `org.address.countryCode`.
 *
 * A RECORD THAT STORES ITS OWN CURRENCY IS SHOWN IN THAT CURRENCY
 *
 * Nine kinds of record keep a `currency` column (a gift, a pledge campaign, a
 * project, a payment, a bank import, an asset, a venue agreement, booking and
 * invoice). Those are written in the organisation's currency at the moment
 * they are made, and shown in their OWN currency afterwards, never in today's
 * setting. The column has NO DEFAULT since #966: a writer that forgets to name
 * it fails loudly instead of storing pounds (that is how every new project was
 * stored as GBP whatever the setting said, measured on 219cb616 in
 * `projects/manage-save.php`).
 *
 * MONEY ALREADY RECORDED (an owner question, recorded on #966)
 *
 * Other records keep only a number — an expense claim, a member's pledge, a
 * collection count, a giving statement — and no total anywhere is added up
 * currency by currency. So switching an organisation from pounds to euros
 * after money is recorded would quietly relabel £500 of claims as €500 and add
 * pounds to euros in every total. Until the owner decides otherwise, an
 * organisation's currency may change only while it has NO money recorded
 * (whyCannotChange()). Choosing a currency for the FIRST time is always
 * allowed: a figure shown as a plain number has no currency to relabel.
 *
 * HUNDREDTHS: WHY ONLY SOME CURRENCIES CAN BE CHOSEN
 *
 * Every amount is stored as a whole number of hundredths ("pence"). A currency
 * whose smallest unit is not a hundredth — the Japanese yen has none, the
 * Kuwaiti dinar has thousandths — would be stored, shown and SENT TO A
 * PAYMENT PROVIDER 100 or 10 times wrong (Stripe takes yen in whole yen, so
 * ¥1,234 stored as 123400 would be charged as ¥123,400). So such a currency
 * cannot be chosen, and a country that uses one gets no suggestion; the page
 * says why. NOT_HUNDREDTHS below is ISO 4217's own list of minor units other
 * than two, written out by hand. ICU was NOT used for it: ICU's figure is how
 * many places are usually SHOWN (it says 0 for the Hungarian forint, which
 * ISO 4217 gives two), not the unit a payment provider counts in.
 *
 * WHERE THE COUNTRY TABLE COMES FROM
 *
 * OF_COUNTRY was generated once from ICU, the library behind PHP's "intl"
 * extension (`NumberFormatter('en_<country>')`'s currency code), for the 247
 * countries Countries::NAMES offers, on PHP 8.5 with intl, 11 November 2026.
 * It is written out here rather than asked of intl at run time, because intl
 * is not an extension the installer requires (the same reason Countries
 * writes out its names). Antarctica has no currency, so it maps to ''.
 * SYMBOLS are ICU's English symbols where they differ from the code; every
 * other currency is written with its code ("CHF 12.50"), which is never wrong.
 *
 * WHY THE INSTALLER CAN LOAD THIS FILE
 *
 * The installer has no autoloader (#728). Everything here except the
 * database readers (ofInstallation, ownOf, ownStoredText, ofOrganisation,
 * ofSite) uses nothing but PHP, so `require_once` of this file is enough.
 * The installer must never call those readers: they reach `App` and `Site`.
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/966
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

final class Currency
{
    /** The setting that holds a currency: installation-wide, and per organisation (#677). */
    public const SETTING = 'org.currency';

    /**
     * 🌍 The currency each country uses: two-letter country => three-letter
     * ISO 4217 code ('' = none). See the file header for where it came from.
     *
     * @var array<string, string>
     */
    private const OF_COUNTRY = [
        'AD' => 'EUR', 'AE' => 'AED', 'AF' => 'AFN', 'AG' => 'XCD', 'AI' => 'XCD', 'AL' => 'ALL', 'AM' => 'AMD', 'AO' => 'AOA',
        'AQ' => '', 'AR' => 'ARS', 'AS' => 'USD', 'AT' => 'EUR', 'AU' => 'AUD', 'AW' => 'AWG', 'AX' => 'EUR', 'AZ' => 'AZN',
        'BA' => 'BAM', 'BB' => 'BBD', 'BD' => 'BDT', 'BE' => 'EUR', 'BF' => 'XOF', 'BG' => 'EUR', 'BH' => 'BHD', 'BI' => 'BIF',
        'BJ' => 'XOF', 'BL' => 'EUR', 'BM' => 'BMD', 'BN' => 'BND', 'BO' => 'BOB', 'BQ' => 'USD', 'BR' => 'BRL', 'BS' => 'BSD',
        'BT' => 'BTN', 'BW' => 'BWP', 'BY' => 'BYN', 'BZ' => 'BZD', 'CA' => 'CAD', 'CC' => 'AUD', 'CD' => 'CDF', 'CF' => 'XAF',
        'CG' => 'XAF', 'CH' => 'CHF', 'CI' => 'XOF', 'CK' => 'NZD', 'CL' => 'CLP', 'CM' => 'XAF', 'CN' => 'CNY', 'CO' => 'COP',
        'CR' => 'CRC', 'CU' => 'CUP', 'CV' => 'CVE', 'CW' => 'XCG', 'CX' => 'AUD', 'CY' => 'EUR', 'CZ' => 'CZK', 'DE' => 'EUR',
        'DJ' => 'DJF', 'DK' => 'DKK', 'DM' => 'XCD', 'DO' => 'DOP', 'DZ' => 'DZD', 'EC' => 'USD', 'EE' => 'EUR', 'EG' => 'EGP',
        'EH' => 'MAD', 'ER' => 'ERN', 'ES' => 'EUR', 'ET' => 'ETB', 'FI' => 'EUR', 'FJ' => 'FJD', 'FK' => 'FKP', 'FM' => 'USD',
        'FO' => 'DKK', 'FR' => 'EUR', 'GA' => 'XAF', 'GB' => 'GBP', 'GD' => 'XCD', 'GE' => 'GEL', 'GF' => 'EUR', 'GG' => 'GBP',
        'GH' => 'GHS', 'GI' => 'GIP', 'GL' => 'DKK', 'GM' => 'GMD', 'GN' => 'GNF', 'GP' => 'EUR', 'GQ' => 'XAF', 'GR' => 'EUR',
        'GS' => 'GBP', 'GT' => 'GTQ', 'GU' => 'USD', 'GW' => 'XOF', 'GY' => 'GYD', 'HK' => 'HKD', 'HN' => 'HNL', 'HR' => 'EUR',
        'HT' => 'HTG', 'HU' => 'HUF', 'ID' => 'IDR', 'IE' => 'EUR', 'IL' => 'ILS', 'IM' => 'GBP', 'IN' => 'INR', 'IO' => 'USD',
        'IQ' => 'IQD', 'IR' => 'IRR', 'IS' => 'ISK', 'IT' => 'EUR', 'JE' => 'GBP', 'JM' => 'JMD', 'JO' => 'JOD', 'JP' => 'JPY',
        'KE' => 'KES', 'KG' => 'KGS', 'KH' => 'KHR', 'KI' => 'AUD', 'KM' => 'KMF', 'KN' => 'XCD', 'KP' => 'KPW', 'KR' => 'KRW',
        'KW' => 'KWD', 'KY' => 'KYD', 'KZ' => 'KZT', 'LA' => 'LAK', 'LB' => 'LBP', 'LC' => 'XCD', 'LI' => 'CHF', 'LK' => 'LKR',
        'LR' => 'LRD', 'LS' => 'ZAR', 'LT' => 'EUR', 'LU' => 'EUR', 'LV' => 'EUR', 'LY' => 'LYD', 'MA' => 'MAD', 'MC' => 'EUR',
        'MD' => 'MDL', 'ME' => 'EUR', 'MF' => 'EUR', 'MG' => 'MGA', 'MH' => 'USD', 'MK' => 'MKD', 'ML' => 'XOF', 'MM' => 'MMK',
        'MN' => 'MNT', 'MO' => 'MOP', 'MP' => 'USD', 'MQ' => 'EUR', 'MR' => 'MRU', 'MS' => 'XCD', 'MT' => 'EUR', 'MU' => 'MUR',
        'MV' => 'MVR', 'MW' => 'MWK', 'MX' => 'MXN', 'MY' => 'MYR', 'MZ' => 'MZN', 'NA' => 'NAD', 'NC' => 'XPF', 'NE' => 'XOF',
        'NF' => 'AUD', 'NG' => 'NGN', 'NI' => 'NIO', 'NL' => 'EUR', 'NO' => 'NOK', 'NP' => 'NPR', 'NR' => 'AUD', 'NU' => 'NZD',
        'NZ' => 'NZD', 'OM' => 'OMR', 'PA' => 'PAB', 'PE' => 'PEN', 'PF' => 'XPF', 'PG' => 'PGK', 'PH' => 'PHP', 'PK' => 'PKR',
        'PL' => 'PLN', 'PM' => 'EUR', 'PN' => 'NZD', 'PR' => 'USD', 'PS' => 'ILS', 'PT' => 'EUR', 'PW' => 'USD', 'PY' => 'PYG',
        'QA' => 'QAR', 'RE' => 'EUR', 'RO' => 'RON', 'RS' => 'RSD', 'RU' => 'RUB', 'RW' => 'RWF', 'SA' => 'SAR', 'SB' => 'SBD',
        'SC' => 'SCR', 'SD' => 'SDG', 'SE' => 'SEK', 'SG' => 'SGD', 'SH' => 'SHP', 'SI' => 'EUR', 'SJ' => 'NOK', 'SK' => 'EUR',
        'SL' => 'SLE', 'SM' => 'EUR', 'SN' => 'XOF', 'SO' => 'SOS', 'SR' => 'SRD', 'SS' => 'SSP', 'ST' => 'STN', 'SV' => 'USD',
        'SX' => 'XCG', 'SY' => 'SYP', 'SZ' => 'SZL', 'TC' => 'USD', 'TD' => 'XAF', 'TF' => 'EUR', 'TG' => 'XOF', 'TH' => 'THB',
        'TJ' => 'TJS', 'TK' => 'NZD', 'TL' => 'USD', 'TM' => 'TMT', 'TN' => 'TND', 'TO' => 'TOP', 'TR' => 'TRY', 'TT' => 'TTD',
        'TV' => 'AUD', 'TW' => 'TWD', 'TZ' => 'TZS', 'UA' => 'UAH', 'UG' => 'UGX', 'UM' => 'USD', 'US' => 'USD', 'UY' => 'UYU',
        'UZ' => 'UZS', 'VA' => 'EUR', 'VC' => 'XCD', 'VE' => 'VES', 'VG' => 'USD', 'VI' => 'USD', 'VN' => 'VND', 'VU' => 'VUV',
        'WF' => 'XPF', 'WS' => 'WST', 'YE' => 'YER', 'YT' => 'EUR', 'ZA' => 'ZAR', 'ZM' => 'ZMW', 'ZW' => 'ZWG',
    ];

    /**
     * ✏️ ISO 4217 currencies whose smallest unit is NOT a hundredth, with the
     * number of decimal places ISO gives them. MGA and MRU are listed although
     * ISO writes "2" for them, because their smallest unit is a FIFTH (and
     * Stripe takes MGA in whole units). None of these may be chosen.
     *
     * @var array<string, int>
     */
    private const NOT_HUNDREDTHS = [
        'BHD' => 3, 'BIF' => 0, 'CLF' => 4, 'CLP' => 0, 'DJF' => 0, 'GNF' => 0, 'IQD' => 3, 'ISK' => 0,
        'JOD' => 3, 'JPY' => 0, 'KMF' => 0, 'KRW' => 0, 'KWD' => 3, 'LYD' => 3, 'MGA' => 1, 'MRU' => 1,
        'OMR' => 3, 'PYG' => 0, 'RWF' => 0, 'TND' => 3, 'UGX' => 0, 'UYI' => 0, 'UYW' => 4, 'VND' => 0,
        'VUV' => 0, 'XAF' => 0, 'XOF' => 0, 'XPF' => 0,
    ];

    /**
     * 💷 English symbols that differ from the code (ICU's). Each is unique, so
     * "$" is only ever US dollars; Canadian dollars are "CA$".
     *
     * @var array<string, string>
     */
    private const SYMBOLS = [
        'AUD' => 'A$', 'BRL' => 'R$', 'CAD' => 'CA$', 'CNY' => 'CN¥', 'EUR' => '€', 'GBP' => '£',
        'HKD' => 'HK$', 'ILS' => '₪', 'INR' => '₹', 'MXN' => 'MX$', 'NZD' => 'NZ$', 'PHP' => '₱',
        'TWD' => 'NT$', 'USD' => '$', 'XCD' => 'EC$',
    ];

    /**
     * 💳 The currencies an online payment may be taken in: two-place currencies
     * that both Stripe and PayPal list (PayPal's list is the shorter; HUF, JPY
     * and TWD are on it but PayPal takes them without decimals, so they are
     * left out). Until #966 only GBP, EUR and USD were allowed; an organisation
     * in any other currency has payments refused with the reason, before
     * anything reaches a provider, rather than charged in somebody else's money.
     * NOT checked against a live provider here: there are no keys on this
     * machine, and a provider that refuses a currency fails the checkout openly.
     *
     * @var list<string>
     */
    public const PAYMENT_CURRENCIES = [
        'AUD', 'BRL', 'CAD', 'CHF', 'CNY', 'CZK', 'DKK', 'EUR', 'GBP', 'HKD', 'ILS',
        'MXN', 'MYR', 'NOK', 'NZD', 'PHP', 'PLN', 'SEK', 'SGD', 'THB', 'USD',
    ];

    /**
     * 💰 Where an organisation's money is recorded: table => extra condition
     * ('' = every row of that organisation is money). Asked by
     * whyCannotChange(). A table missing from here would let the currency
     * change under money it holds, so section C of the self-test compares this
     * list with the schema and fails both ways. The provider-spend tables
     * (translation, transcription, AI, texts) are deliberately NOT here: that
     * is the money the INSTALLATION pays its providers, not the organisation's.
     *
     * @var array<string, string>
     */
    public const MONEY_TABLES = [
        'tblAssetMaintenance'    => '`costPence` IS NOT NULL',
        'tblAssets'              => '(`purchaseCostPence` IS NOT NULL OR `salvageValuePence` IS NOT NULL OR `currentValuePence` IS NOT NULL OR `insuredValuePence` IS NOT NULL)',
        'tblBankImports'         => '',
        'tblBankTxns'            => '',
        'tblCountSessions'       => '',
        'tblExpenseClaims'       => '',
        'tblGivingEntry'         => '',
        'tblGivingStatementLog'  => '',
        'tblPayment'             => '',
        'tblPledgeCampaigns'     => '',
        'tblPledges'             => '',
        'tblProject'             => '',
        'tblResource'            => '`hourlyRatePence` IS NOT NULL',
        'tblVenueAgreements'     => '',
        'tblVenueBookings'       => '`costPence` IS NOT NULL',
        'tblVenueInvoices'       => '',
    ];

    /** 🌍 The currency a country uses ('' for none or an unknown country). Two capital letters only. */
    public static function ofCountry(string $countryCode): string
    {
        return self::OF_COUNTRY[$countryCode] ?? '';
    }

    /**
     * 💡 The currency the portal SUGGESTS for a country: its currency when that
     * can be chosen, else '' (a country that uses yen gets no suggestion, and
     * whyNotChoosable() says why).
     */
    public static function suggestFor(string $countryCode): string
    {
        $code = self::ofCountry($countryCode);

        return self::isChoosable($code) === true ? $code : '';
    }

    /** 🗺️ Every offered country => its suggested currency (''), for the installer's script. @return array<string, string> */
    public static function countryMap(): array
    {
        $map = [];
        foreach (array_keys(self::OF_COUNTRY) as $country) {
            $map[$country] = self::suggestFor($country);
        }

        return $map;
    }

    /** ✅ Is this a currency the portal knows at all (three capital letters it has heard of)? */
    public static function isKnown(string $code): bool
    {
        return in_array($code, self::OF_COUNTRY, true) === true || isset(self::SYMBOLS[$code]) === true;
    }

    /** ✅ May this currency be chosen? Known, and counted in hundredths. */
    public static function isChoosable(string $code): bool
    {
        return self::isKnown($code) === true && isset(self::NOT_HUNDREDTHS[$code]) === false;
    }

    /**
     * 🗣️ Why a currency cannot be chosen, in plain words, or '' when it can.
     * Shown on the organisation page when its country's currency is one of
     * these, so nobody is left wondering why nothing was suggested.
     */
    public static function whyNotChoosable(string $code): string
    {
        if ($code === '' || self::isChoosable($code) === true) {
            return '';
        }
        if (isset(self::NOT_HUNDREDTHS[$code]) === true) {
            return $code . ' cannot be chosen yet: the portal stores every amount in hundredths of a unit, and '
                . $code . ' is not counted in hundredths, so amounts would be stored and charged wrongly.';
        }

        return $code . ' is not a currency the portal knows.';
    }

    /**
     * 📋 Every currency that may be chosen, code => "CODE (symbol)", sorted by
     * code, for a drop-down.
     *
     * @return array<string, string>
     */
    public static function choices(): array
    {
        $codes = array_unique(array_merge(array_values(self::OF_COUNTRY), array_keys(self::SYMBOLS)));
        $list  = [];
        foreach ($codes as $code) {
            if ($code !== '' && self::isChoosable($code) === true) {
                $list[$code] = isset(self::SYMBOLS[$code]) === true ? $code . ' (' . self::SYMBOLS[$code] . ')' : $code;
            }
        }
        ksort($list, SORT_STRING);

        return $list;
    }

    /**
     * 🧹 A posted currency, tidied: '' when nothing was chosen, the code in
     * capitals when it may be chosen, and null for anything else, which the
     * caller REFUSES. Never a made-up currency: an empty box stays empty.
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

        return self::isChoosable($code) === true ? $code : null;
    }

    /** 💷 The currency's own symbol ("£"), or '' when it is written with its code. */
    public static function symbol(string $code): string
    {
        return self::SYMBOLS[$code] ?? '';
    }

    /**
     * 💷 What goes in front of a number: "£", "CHF " (code and a space), or ''
     * when no currency is known — a plain number, NEVER a pound sign.
     */
    public static function prefix(string $code): string
    {
        $code = strtoupper(trim($code));
        if ($code === '') {
            return '';
        }
        $symbol = self::symbol($code);

        return $symbol !== '' ? $symbol : $code . ' ';
    }

    /** 🏷️ A label for a box or a column title: "£", "CHF", or '' when no currency is known. */
    public static function label(string $code): string
    {
        return trim(self::prefix($code));
    }

    /**
     * 💷 An amount stored in hundredths, written for a person: "£1,234.50",
     * "-€3.00", "CHF 12.00", or "12.00" with no currency known. A negative
     * amount puts the minus sign first ("-£3.00"), which is how a person writes
     * it; the old hand-written copies wrote "£-3.00".
     */
    public static function format(int $hundredths, string $code): string
    {
        $sign = $hundredths < 0 ? '-' : '';

        return $sign . self::prefix($code) . number_format(abs($hundredths) / 100, 2);
    }

    /**
     * 💷 An amount already in whole units (an expense's "12.50", stored as a
     * decimal of the organisation's currency), written the same way as format().
     */
    public static function formatMajor(float|int|string $amount, string $code): string
    {
        $value = (float) $amount;
        $sign  = $value < 0 ? '-' : '';

        return $sign . self::prefix($code) . number_format(abs($value), 2);
    }

    /**
     * 🏢 The INSTALLATION's currency (its own row, `siteID IS NULL`), or ''.
     * Read from the database, not from the merged settings, because the
     * merged value is the open organisation's when it has its own (#674).
     */
    public static function ofInstallation(): string
    {
        return self::readRows(null)['installation'];
    }

    /**
     * 🏠 An organisation's OWN currency (its own row), or '' when it follows
     * the installation's. What its settings page puts in the box, so a Save
     * does not copy the installation's currency into the organisation's own row.
     */
    public static function ownOf(int $siteId): string
    {
        return self::readRows($siteId)['own'];
    }

    /**
     * 🏠 What an organisation's own row HOLDS, trimmed ('' when it has no row).
     * Three letters in any case come back in capitals. Anything else comes back
     * as stored, so the page can SAY that it is not a currency rather than
     * show "Same as the installation" over it (Countries::ownStoredText(),
     * round 3 of #942's check, is the same lesson).
     */
    public static function ownStoredText(int $siteId): string
    {
        $text = self::readRows($siteId)['ownText'];

        return preg_match('/^[A-Za-z]{3}$/', $text) === 1 ? strtoupper($text) : $text;
    }

    /**
     * 💷 The currency an organisation IS in: its own when it has chosen one,
     * else the installation's, else '' (none known: amounts are plain numbers).
     * The ONE answer for every reader that needs "this organisation's money".
     * A row holding anything that is not a choosable currency counts as "not
     * chosen", exactly as Countries::ofOrganisation() treats a bad country.
     */
    public static function ofOrganisation(int $siteId): string
    {
        $rows = self::readRows($siteId);

        return $rows['own'] !== '' ? $rows['own'] : $rows['installation'];
    }

    /** 💷 The currency of the organisation this request is for. */
    public static function ofSite(): string
    {
        return self::ofOrganisation(Site::id());
    }

    /**
     * 🔒 Why this organisation's currency may NOT change from `$now` to `$next`,
     * in plain words, or '' when it may.
     *
     * It may always change while no currency is in force (`$now` is ''): a
     * figure shown as a plain number has no currency to relabel. Otherwise it
     * may change only while the organisation has no money recorded in any of
     * MONEY_TABLES. With `$lock` true each table is read with a locking read
     * (`FOR UPDATE`), which under InnoDB's REPEATABLE READ also blocks a new row
     * for this organisation until the caller's transaction ends — so nothing
     * can be recorded between this answer and the caller's write (#647). The
     * lock is real only inside the caller's transaction (#697). A table that
     * cannot be read counts as HOLDING money: "we could not tell" must never
     * become "go ahead".
     */
    public static function whyCannotChange(\mysqli $db, int $siteId, string $now, string $next, bool $lock): string
    {
        if ($now === '' || $now === $next) {
            return '';
        }
        foreach (self::MONEY_TABLES as $table => $condition) {
            $sql = 'SELECT 1 FROM `' . $table . '` WHERE `siteID` = ?'
                . ($condition !== '' ? ' AND ' . $condition : '')
                . ' LIMIT 1' . ($lock === true ? ' FOR UPDATE' : '');
            try {
                $stmt = $db->prepare($sql);
                if ($stmt === false) {
                    return self::lockedWords($now, 'the records could not be checked');
                }
                $stmt->bind_param('i', $siteId);
                $stmt->execute();
                $found = $stmt->get_result()->fetch_row() !== null;
                $stmt->close();
            } catch (\Throwable $e) {
                return self::lockedWords($now, 'the records could not be checked');
            }
            if ($found === true) {
                return self::lockedWords($now, 'money is already recorded in it');
            }
        }

        return '';
    }

    /**
     * 🔒 Does the general Settings editor refuse this key? Yes for the currency:
     * that editor writes a row with no money check, so it would let a currency
     * change under recorded money. The organisation settings page is the ONE
     * place it is changed. Capitals are ignored, as a key's are everywhere.
     */
    public static function lockedInGeneralEditor(string $key): bool
    {
        return strcasecmp($key, self::SETTING) === 0;
    }

    /** What the general settings editor says when it refuses the currency. */
    public const LOCKED_MESSAGE = 'The currency can only be changed at Admin, Settings, Organisation, where a change '
        . 'that would relabel money already recorded is refused. Nothing was changed.';

    /** The one sentence for a currency that cannot change. */
    private static function lockedWords(string $now, string $because): string
    {
        return 'This organisation\'s currency is ' . $now . ' and cannot be changed, because ' . $because
            . '. Amounts already recorded have no currency of their own, so changing it would relabel them '
            . '(£500 of claims would start showing as 500 of the new currency) and add two currencies together '
            . 'in every total.';
    }

    /**
     * Both rows in one query. A read that fails is "nothing known" (''),
     * never a made-up currency.
     *
     * @return array{own: string, installation: string, ownText: string}
     */
    private static function readRows(?int $siteId): array
    {
        $answer = ['own' => '', 'installation' => '', 'ownText' => ''];
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
                $answer[$which] = self::isChoosable($code) === true ? $code : '';
                if ($which === 'own') {
                    $answer['ownText'] = trim((string) ($row['settingValue'] ?? ''));
                }
            }
            $stmt->close();
        } catch (\Throwable $e) {
            return ['own' => '', 'installation' => '', 'ownText' => ''];
        }

        return $answer;
    }
}
