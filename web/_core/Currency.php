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
 *   parseAmount(),                a TYPED amount read strictly into whole
 *   parseHundredths(), …          hundredths, or refused with the reason (#1004).
 *   addTo(), totalsWords()        totals kept and written PER CURRENCY, never
 *                                 added across currencies (D-139).
 *   unlabelledCount(),            rows saved with no currency (''), and labelling
 *   labelUnlabelled()             them deliberately with one an administrator
 *                                 chooses (never guessed).
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
 * EVERY AMOUNT KEEPS ITS OWN CURRENCY (D-139, the owner, 11 November 2026)
 *
 * Every table that holds money has a `currency` column beside the amount
 * (nine had one before #966; migration 283 added it to fourteen more). A row
 * is written in the organisation's currency at the moment it is made — or,
 * for an expense line, in the currency it was QUOTED in, beside the CONVERTED
 * amount in the organisation's — and is shown in its OWN currency afterwards,
 * never in today's setting. So an organisation's currency CAN change (D-133,
 * D-139): old records keep theirs. Every total is added up PER CURRENCY and
 * never across currencies (addTo(), totalsWords()); a total with two
 * currencies is two lines. (Round 1 of #966 locked the currency once money
 * was recorded instead; the owner chose this, and the lock is gone.)
 *
 * The nine older columns have NO default, so a writer that forgets the
 * currency fails loudly (that is how every new project used to be stored as
 * GBP whatever the setting said). The fourteen newer ones default to '',
 * because a whole-database backup taken before migration 283 has none of
 * those columns and must still restore. '' means "not recorded": the amount
 * is shown as a plain number, and Organisation settings offers to label an
 * organisation's unlabelled rows with a currency an administrator CHOOSES
 * (labelUnlabelled()) — it is never guessed, because only a person knows
 * what money an old figure was.
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
     * 💷 English symbols that differ from the code (ICU's), used to WRITE an
     * amount (format(), prefix()). Each is distinct within this list, so the
     * portal never writes two currencies the same way ("$" here is US dollars,
     * Canadian dollars are "CA$"). That is a fact about this list, not about
     * the world: several currencies are written "$" in practice, which is what
     * SIGNS_OF records for READING a file.
     *
     * @var array<string, string>
     */
    private const SYMBOLS = [
        'AUD' => 'A$', 'BRL' => 'R$', 'CAD' => 'CA$', 'CNY' => 'CN¥', 'EUR' => '€', 'GBP' => '£',
        'HKD' => 'HK$', 'ILS' => '₪', 'INR' => '₹', 'MXN' => 'MX$', 'NZD' => 'NZ$', 'PHP' => '₱',
        'TWD' => 'NT$', 'USD' => '$', 'XCD' => 'EC$',
    ];

    /**
     * 💱 The signs each currency is WRITTEN with, for reading an imported file
     * (the owner, D-165, which supersedes D-155 and D-157): a cell's sign is read
     * as the organisation's own currency whenever that currency is written with
     * it — "$" is US dollars in a USD organisation and Australian dollars in an
     * AUD one. What a file cell starting with any OTHER sign is told depends
     * on whether the converter recognises it as a currency marker
     * (anyMarkerAtStart(): a sign in this list, capitals joined to one, or a
     * known code followed by an amount or a sign). If it does, and
     * isForeignMarker() finds it is not the organisation's, the cell is told
     * it is not read as that currency HERE (otherCurrencyWords()) — a claim
     * about this portal, because this list is ICU's English data, not every
     * way a currency is written ("₱" for the Cuban peso and "£" for the
     * Egyptian pound are real and not listed). Every other start — a currency
     * sign this list does not hold at all ("₫12"), or "US$12" in a US-dollar
     * organisation, whose "$" IS its own — could not be read
     * (FILE_REASON_UNREADABLE). The three-letter
     * code is always read as well.
     *
     * WHERE IT CAME FROM (sourced, not remembered — round 5's check found a
     * hand-written list naming "Mexican dollars" and a Cuban "₱" no source
     * gives): ICU 79.1's English currency data, the library behind PHP's intl
     * extension, read once on 12 November 2026 with
     * `ResourceBundle::create('en', 'ICUDATA-curr')` — each currency's
     * standard symbol (`Currencies/<code>/0`) and its NARROW symbol
     * (`Currencies%narrow/<code>`), keeping each that is not just the code,
     * for every currency choices() offers. So the Cuban peso is "$" (ICU's
     * narrow symbol), not "₱", which ICU gives the Philippine peso alone; the
     * Egyptian pound is "E£" and the Lebanese "L£", not a bare "£". It is
     * written out here rather than read from intl at run time, because intl is
     * not an extension the installer requires (as OF_COUNTRY). A currency not
     * listed has no sign in ICU's English data, and is read by its code only.
     * The entries made only of LETTERS ("R", "kr", "Rs", "Fr.") are ICU's data
     * too, but are NOT read in a file (the owner, D-177): signsOf() leaves them
     * out, and a cell written with one is refused asking for the code.
     *
     * @var array<string, list<string>>
     */
    private const SIGNS_OF = [
        'AFN' => ['؋'], 'AMD' => ['֏'], 'AOA' => ['Kz'], 'ARS' => ['$'], 'AUD' => ['A$', '$'], 'AZN' => ['₼'],
        'BAM' => ['KM'], 'BBD' => ['$'], 'BDT' => ['৳'], 'BMD' => ['$'], 'BND' => ['$'], 'BOB' => ['Bs'], 'BRL' => ['R$'],
        'BSD' => ['$'], 'BWP' => ['P'], 'BZD' => ['$'], 'CAD' => ['CA$', '$'], 'CHF' => ['Fr.'], 'CNY' => ['CN¥', '¥'],
        'COP' => ['$'], 'CRC' => ['₡'], 'CUP' => ['$'], 'CZK' => ['Kč'], 'DKK' => ['kr'], 'DOP' => ['$'], 'EGP' => ['E£'],
        'EUR' => ['€'], 'FJD' => ['$'], 'FKP' => ['£'], 'GBP' => ['£'], 'GEL' => ['₾'], 'GHS' => ['GH₵'],
        'GIP' => ['£'], 'GTQ' => ['Q'], 'GYD' => ['$'], 'HKD' => ['HK$', '$'], 'HNL' => ['L'], 'HUF' => ['Ft'], 'IDR' => ['Rp'],
        'ILS' => ['₪'], 'INR' => ['₹'], 'JMD' => ['$'], 'KGS' => ['⃀'], 'KHR' => ['៛'], 'KPW' => ['₩'], 'KYD' => ['$'],
        'KZT' => ['₸'], 'LAK' => ['₭'], 'LBP' => ['L£'], 'LKR' => ['Rs'], 'LRD' => ['$'], 'MMK' => ['K'], 'MNT' => ['₮'],
        'MUR' => ['Rs'], 'MXN' => ['MX$', '$'], 'MYR' => ['RM'], 'NAD' => ['$'], 'NGN' => ['₦'], 'NIO' => ['C$'],
        'NOK' => ['kr'], 'NPR' => ['Rs'], 'NZD' => ['NZ$', '$'], 'PHP' => ['₱'], 'PKR' => ['Rs'], 'PLN' => ['zł'],
        'RON' => ['lei'], 'RUB' => ['₽'], 'SBD' => ['$'], 'SEK' => ['kr'], 'SGD' => ['$'], 'SHP' => ['£'], 'SRD' => ['$'],
        'SSP' => ['£'], 'STN' => ['Db'], 'SYP' => ['£'], 'THB' => ['฿'], 'TOP' => ['T$'], 'TRY' => ['₺'], 'TTD' => ['$'],
        'TWD' => ['NT$', '$'], 'UAH' => ['₴'], 'USD' => ['$'], 'UYU' => ['$'], 'XCD' => ['EC$', '$'], 'XCG' => ['Cg.'],
        'ZAR' => ['R'], 'ZMW' => ['ZK'],
    ];

    /**
     * 💱 The signs a FILE cell may be written with for this currency: its
     * SIGNS_OF entries that have a symbol in them ("$", "£", "A$", "R$", "GH₵"),
     * longest first so "CA$" is tried before "$"; [] when it has none.
     *
     * A sign made only of LETTERS ("R", "kr", "Rs", "Fr.", "P", "Rp", "Cg." —
     * letters with at most a trailing point) is left out on purpose (the owner,
     * D-177, superseding D-165 for those signs): such signs clash with ordinary
     * text ("R Smith", "P.O. 1234"), and round 7's check measured "Rs.50"
     * stored as 0.50, a hundred times too small. A cell written with one is
     * refused as a cell that could not be read, with the advice to write the
     * three-letter code. They stay in SIGNS_OF because that is ICU's data.
     *
     * @return list<string>
     */
    public static function signsOf(string $code): array
    {
        $signs = array_values(array_filter(
            self::SIGNS_OF[strtoupper(trim($code))] ?? [],
            static fn (string $sign): bool => self::isLettersOnly($sign) === false
        ));
        usort($signs, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return $signs;
    }

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
     * 💰 The tables that hold an organisation's money DIRECTLY (they carry
     * `siteID` and `currency`). Used by unlabelledCount() and
     * labelUnlabelled(). Section B of tools/currency-selftest.php compares this
     * list and CHILD_TABLES with the schema and fails both ways: a table holding
     * money that is in neither could never be labelled. The provider-spend
     * tables (translation, transcription, AI, texts) are deliberately in
     * neither: that is the INSTALLATION's spending, estimated in hundredths
     * from US-dollar list prices, and what currency those figures are in is
     * its own question (see #966's notes).
     *
     * @var list<string>
     */
    public const OWN_TABLES = [
        'tblAssets', 'tblBankImports', 'tblCountSessions', 'tblExpenseClaims', 'tblGivingEntry',
        'tblGivingStatementLog', 'tblPayment', 'tblPledgeCampaigns', 'tblProject', 'tblResource',
        'tblVenueAgreements', 'tblVenueBookings', 'tblVenueInvoices',
    ];

    /**
     * 🔗 Tables whose money belongs to a PARENT row: child => [parent table,
     * the child's column, the parent's key]. A child is labelled with its
     * parent's currency, so a repair to an asset bought in francs stays in
     * francs. Some of these also carry `siteID`; they are still labelled
     * through their parent, which is the truer answer.
     *
     * @var array<string, array{0: string, 1: string, 2: string}>
     */
    public const CHILD_TABLES = [
        'tblAssetMaintenance'     => ['tblAssets', 'assetID', 'assetID'],
        'tblAssetValueHistory'    => ['tblAssets', 'assetID', 'assetID'],
        'tblBankTxns'             => ['tblBankImports', 'importID', 'importID'],
        'tblCountEnvelopes'       => ['tblCountSessions', 'countSessionID', 'countSessionID'],
        'tblExpenseClaimItems'    => ['tblExpenseClaims', 'claimID', 'claimID'],
        'tblExpenseClaimPayments' => ['tblExpenseClaims', 'claimID', 'claimID'],
        'tblPledges'              => ['tblPledgeCampaigns', 'campaignID', 'campaignID'],
        'tblProjectPledge'        => ['tblProject', 'projectID', 'projectID'],
        'tblVenueInvoiceLines'    => ['tblVenueInvoices', 'invoiceID', 'invoiceID'],
        'tblVenueInvoicePayments' => ['tblVenueInvoices', 'invoiceID', 'invoiceID'],
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

    /**
     * ✏️ THE ONE RULE for the currency an EDITED money record is written with
     * (#966, D-139). It returns the record's STORED currency when no currency
     * was posted, and the POSTED one when one was. Never today's currency: an
     * edit after the organisation changed its own must not relabel a record,
     * and a record with no recorded currency ('') STAYS '' unless one is posted
     * for it. It does NOT decide whether a posted change is allowed: that is
     * the caller's refusal (whyCurrencyCannotChange() for an asset,
     * whyInvoiceCurrencyCannotChange() for an invoice), and a caller may ask
     * this rule more than once — saveInvoice() asks it before the invoice's
     * lock and again under it, with the stored currency the lock read.
     *
     * Every UPDATE of a money table under web/ that writes `currency` asks this
     * (tools/audit-checks/check_currency_writes.py is the hard gate). "An edit
     * relabels a record" failed two check rounds running, each time through a
     * different caller's own "fall back to the organisation's" branch: one rule
     * in one place is the fix for the class, not a fifth such branch.
     *
     * $posted: null or '' when no currency was posted; otherwise a code the
     * caller has ALREADY checked with isChoosable() (an unusable code is the
     * caller's refusal, in its own words).
     */
    public static function onEdit(string $stored, ?string $posted): string
    {
        $wanted = strtoupper(trim((string) $posted));
        if ($wanted === '') {
            return $stored;
        }

        return $wanted;
    }

    /**
     * 🔒 The parent tables whose currency can CHANGE after the row is made, and
     * the key column of each: the only tables withParentLocked() will lock. An
     * asset's currency changes on its edit page; a venue invoice's on its own.
     * Every other parent keeps the currency it was made in (only the labelling
     * of unrecorded rows ever writes it, in its own transaction).
     */
    public const LOCKABLE_PARENTS = [
        'tblAssets'        => 'assetID',
        'tblVenueInvoices' => 'invoiceID',
    ];

    /**
     * 🔒 THE ONE OWNER of "lock the parent, re-read, write" for money (#966,
     * D-139; #647's rule). It BEGINS a transaction, then locks the parent row
     * FOR UPDATE as its FIRST statement, then calls $work with the currency
     * that locked read answered (null when there is no such row in this
     * organisation), then COMMITS. On ANY \Throwable — a failed statement under
     * the portal's strict reporting, a lock-wait timeout (1205), a bug — it
     * ROLLS BACK and rethrows, so nothing escapes with the transaction open.
     *
     * $work re-reads what it needs (the parent's children, its own refusal)
     * UNDER that lock and writes; it returns its own answer, which this
     * returns. It must not begin, commit or roll back itself. A refusal is a
     * plain return: nothing was written, and the empty commit is harmless.
     *
     * Why one helper: the round-5 copies in six methods each began, locked and
     * rolled back by hand, and a lock-wait timeout threw past three of them with
     * the transaction still open, so the error row written next vanished with
     * it on disconnect (#694's round-3 shape). `if ($stmt === false)` cannot
     * catch that: under MYSQLI_REPORT_STRICT | MYSQLI_REPORT_ERROR the
     * statement THROWS. Every parent-currency writer and child insert of a
     * table in LOCKABLE_PARENTS calls this (check_currency_writes.py pins it).
     *
     * The lock only means something inside the transaction (#697): a locking
     * read in autocommit is released at once. tools/currency-selftest.php
     * (section L) proves the transaction is open while $work runs.
     *
     * @template T
     * @param callable(?string): T $work
     * @return T
     */
    public static function withParentLocked(\mysqli $db, string $parentTable, int $parentId, int $siteId, callable $work): mixed
    {
        $key = self::LOCKABLE_PARENTS[$parentTable] ?? null;
        if ($key === null) {
            throw new \InvalidArgumentException('withParentLocked(): ' . $parentTable . ' is not a parent whose currency can change.');
        }
        $db->begin_transaction();
        try {
            $stmt = $db->prepare('SELECT `currency` FROM `' . $parentTable . '` WHERE `' . $key . '` = ? AND `siteID` = ? FOR UPDATE');
            $stmt->bind_param('ii', $parentId, $siteId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $result = $work(is_array($row) === true ? (string) $row['currency'] : null);
            $db->commit();

            return $result;
        } catch (\Throwable $e) {
            try {
                $db->rollback();
            } catch (\Throwable $ignored) {
                // The connection itself has gone: there is nothing left to roll back.
            }
            throw $e;
        }
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

    // -------------------------------------------------------------------------
    // 🔢 A typed amount → a whole number of hundredths: THE ONE CONVERTER (#1004)
    // -------------------------------------------------------------------------

    /**
     * 💯 The largest number a signed INT column holds: 2,147,483,647. Every
     * `…Pence` money column in web/_sql/full_schema.sql is a signed INT, so
     * this is the most hundredths any of them can store (21,474,836.47).
     */
    public const INT_COLUMN_MAX = 2147483647;

    /**
     * 💯 The largest amount a DECIMAL(10,2) column holds, in hundredths
     * (99,999,999.99). The offering-count totals and named envelopes are kept
     * that way (tblCountSessions, tblCountEnvelopes).
     */
    public const DECIMAL_10_2_MAX = 9999999999;

    /** What parseAmount() answers for an empty box. A caller whose box is optional tests for '' first. */
    public const REFUSED_EMPTY = 'No amount was entered.';

    /** What a typed box answers for a character that is not a figure, a point or a minus (D-152: figures only). */
    public const REFUSED_NOT_FIGURES = 'Type the amount in figures only, for example 1234.50: no commas, spaces, currency signs, letters or other characters.';

    /**
     * What a FILE's amount answers when the converter cannot say for CERTAIN why
     * the cell is not an amount — a cell beginning with letters (a word, or a
     * sign made only of letters, which is never read: D-177), a plus sign, a
     * power of ten, a cell with no figures — followed by fileAdvice(), which
     * says exactly what IS read (#1004 round 9). Rounds 2 to 8 tried to name
     * which OTHER currency such a cell was in, and every attempt produced
     * untrue sentences: "GBP $12" was called another currency, GBP; "P.O. 1234"
     * was called a currency sign; "TOP-UP 20" was called the pa'anga.
     *
     * A cell that begins with a marker anyMarkerAtStart() recognises — a sign listed in SIGNS_OF with a
     * symbol in it, one to three capitals joined to such a sign ("US$"), or a
     * three-letter code the portal knows followed, after any run of spaces,
     * by an amount or a sign ("USD 12", "USD$5") — that isForeignMarker() judges is
     * not the organisation's gets otherCurrencyWords() instead (round 10),
     * which says so WITHOUT naming the other currency. EVERY other start is
     * THIS sentence, currency signs included: a sign SIGNS_OF does not list
     * ("₫12"), "US$12" in a US-dollar, Canadian or Australian dollar
     * organisation (its "$" is the organisation's own), a bare code ("USD"),
     * a code followed by a tab ("USD\t12") or by a word ("USD PAID"). Round 9 gave such a cell this sentence
     * and advice to put the organisation's own code in front, which for "$12"
     * in a sterling organisation invited relabelling dollars as pounds — the
     * opposite of D-165's "refused, with the true reason".
     */
    public const FILE_REASON_UNREADABLE     = 'That amount could not be read.';
    /** A minus where a file's amount may NOT be negative (the asset import). */
    public const FILE_REASON_MINUS_NOT_HERE = 'A minus sign has no place here: the amount cannot be negative.';

    /** The other refusals, each worded for the one shape it is given (#1004). */
    public const REFUSED_POWER_OF_TEN = 'Write the amount out in full figures, for example 1500.00: a power of ten such as 1e3 is not accepted.';
    public const REFUSED_PLUS         = 'Leave out the plus sign: type the amount in figures, for example 12.50.';
    /** A misplaced minus where an amount MAY be negative (the bank import's debits). */
    public const REFUSED_MINUS_PLACE  = 'A minus sign can only come first, for example -12.50.';
    /** A minus where an amount may NOT be negative: "come first" would only lead to "cannot be negative" (round 4). */
    public const REFUSED_MINUS_NOT_HERE      = 'A minus sign has no place here: the amount cannot be negative. Type the figures only, for example 12.50.';
    public const REFUSED_ONE_POINT    = 'An amount can have only one decimal point, for example 12.50.';
    public const REFUSED_NO_FIGURES   = 'That has no figures in it: type the amount in figures, for example 12.50.';
    public const REFUSED_COMMAS       = 'The commas in that amount are in the wrong places: a comma may only separate groups of three figures (1,234.50), and the decimals follow a point (12.50).';
    public const REFUSED_TOO_PRECISE  = 'An amount can have at most two figures after the decimal point, for example 12.50.';
    public const REFUSED_NEGATIVE     = 'The amount cannot be negative.';

    /** How every "too large" refusal begins; the rest is the column's most, written in the currency. */
    public const REFUSED_TOO_LARGE_START = 'That amount is too large: the most that can be stored here is ';

    /**
     * 🔢 THE ONE CONVERTER from a typed amount ("12.50", "-3") to a whole
     * number of hundredths of `$currency`. It answers the number (an int), or a
     * sentence saying truthfully why the text was REFUSED (a string). Nothing
     * is ever guessed, rounded or clamped.
     *
     * WHY IT EXISTS (#1004). Every money box used to do
     * `(int) round((float) $typed * 100)`. A float holds only about sixteen
     * significant figures, and `(int)` of a float outside PHP's integer range
     * is not an error, only a logged warning, so an absurd amount WRAPPED:
     * measured on PHP 8.5, "184467440737095557.12" became 4096 and was stored
     * silently as 40.96; "1e17" became negative, so a page said "Amount cannot
     * be negative", which was untrue; "-1e30" became a large positive number.
     * Other boxes first stripped every character that was not a figure or a
     * point, so "1e5" became 15.00, "-5" became 5.00 and "12,50" (a decimal
     * comma) became 1,250.00. This reads the TEXT, figure by figure, and never
     * goes through a float.
     *
     * A BOX A PERSON TYPES INTO (the default) — FIGURES ONLY (the owner, D-152):
     *   - digits, with at most ONE decimal point and at most TWO figures after
     *     it ("12", "12.5", "12.50", ".50", "12."). Every currency the portal
     *     can store is counted in hundredths (see "Hundredths" in the file
     *     header), so two places is all a stored amount can carry. "12.345" is
     *     REFUSED rather than rounded: rounding a figure somebody typed is
     *     deciding for them;
     *   - NO commas, NO currency sign or code, NO letters, NO inner spaces:
     *     "1,234.50", "£12.50" and "GBP 12.50" are refused with a plain sentence
     *     asking for figures only;
     *   - a leading minus, ONLY when the caller passes `$allowNegative`;
     *   - spaces at either end are ignored.
     *
     * A FILE THE PORTAL READS (`$fromFile = true`): the bank-statement import
     * (D-152) and the asset import (D-153) ONLY. A file keeps the number format
     * the program that wrote it uses, so the converter ALSO reads
     *   - commas between groups of three ("1,234.50", "1,234,567"), the first
     *     group starting 1-9, so "0,500" and "12,50" are refused, never read as
     *     500.00 or 1,250.00;
     *   - the organisation's three-letter CODE in front, where — after any
     *     run of SPACES (a tab is not read: "GBP\t12" is refused, could not be
     *     read) — an amount follows, meaning a figure or a point after at most
     *     one minus ("GBP 12.50", "GBP  12.50", "GBP -3", "AUD1,234.50"). A code
     *     followed by a currency sign instead is taken too, and refused as two
     *     markers ("GBP $12"). "ALL PAID", "ALL-IN" and "TOP-UP 20" are words,
     *     not codes;
     *   - or any sign WITH A SYMBOL IN IT that its currency is written with
     *     (signsOf(), from ICU), again followed by any run of spaces: "$" is
     *     read as US dollars in a USD organisation and as Australian dollars in
     *     an AUD one, "£" as Gibraltar pounds in a GIP one (the owner, D-165,
     *     superseding D-155 and D-157). A minus may sit either side of it
     *     ("-£3.00", "£-3.00"). A sign made only of LETTERS ("R", "kr", "Rs",
     *     "Fr.") is NOT read (the owner, D-177).
     *   A file refusal gives a specific reason ONLY where the converter can know
     *   it for certain (#1004 rounds 9 and 10):
     *     - two currency markers in front: one of the organisation's own
     *       (ownMarkerAtStart()), then a second, which is whatever
     *       ownMarkerAtStart() and then anyMarkerAtStart() find in what
     *       follows. The organisation's code is only taken where an amount or
     *       a sign follows it (a sign in SIGNS_OF, or capitals joined to one),
     *       so after the CODE the second is that sign ("GBP £12.50",
     *       "GBP$12"), capitals joined to one ("GBP US$12"), or — where those
     *       capitals are a code the portal knows — that CODE ("GBP USD$12" and
     *       "GBP ALL$5" take USD and ALL). After one of its SIGNS the second
     *       is any marker either function finds ("££12.50", "£GBP 12.50",
     *       "£$12", "£USD 12"). The code followed by ANOTHER code and a figure
     *       ("GBP USD 12") is not taken at all and could not be read. The
     *       sentence says "Put only one in front" ONLY when the second, on
     *       its own, is one ownMarkerAtStart() reads as this organisation's
     *       currency — the same function that reads a single-marker cell, so
     *       the two can never disagree; every other second says "…, and X is
     *       not read as GBP here." with the convert sentence ("CAD US$12",
     *       "USD US$5": "US$12" alone could not be read in either);
     *     - at the start, after at most one minus, a marker anyMarkerAtStart() recognises — a sign listed in SIGNS_OF with a
     * symbol in it, one to three capitals joined to such a sign ("US$"), or a
     * three-letter code the portal knows followed, after any run of spaces,
     * by an amount or a sign
     *       — that isForeignMarker() judges is not the organisation's ("$12",
     *       "-$3", "US$12", "USD 5", "USD$5" in a GBP organisation):
     *       otherCurrencyWords(), which says it is not read as the
     *       organisation's currency HERE — a claim about this portal, true by
     *       construction — and to convert, never to put its own code in front.
     *       It does NOT name the other currency. Any other start — a sign
     *       SIGNS_OF does not list ("₫12"), "US$12" in a US-dollar
     *       organisation, a bare code, a code followed by a tab or a word —
     *       could not be read;
     *     - more than two decimals; misplaced commas; more than one point; a
     *       minus in the wrong place, or where none is allowed; too large;
     *       nothing entered;
     *     - with NO currency chosen for the organisation, a marker
     *       anyMarkerAtStart() recognises in front (D-139: an amount's money is
     *       never guessed).
     *   Every other file refusal — a word, a letters-only sign (D-177), a sign
     *   the converter does not recognise, a plus, a power of ten, no figures —
     *   is the one sentence FILE_REASON_UNREADABLE
     *   followed by fileAdvice(), which says exactly what is read.
     *
     * In both modes it refuses an empty box, a power of ten ("1e17"), a "+"
     * sign, a misplaced minus, a second decimal point, more than two decimals,
     * a negative where none is allowed, and anything above `$max`, which the
     * caller sets to the most the place the value finally LANDS can hold
     * (INT_COLUMN_MAX, the default; DECIMAL_10_2_MAX for a DECIMAL(10,2)
     * column) or to what its own pattern admits — never lower than a limit
     * checked AFTER it, or that check's own message is never reached. A typed
     * box's refusal names the reason for the shape it was given; a file's
     * names a reason only where it is certain (above). The size test
     * compares the FIGURES as text, so it is exact at any length. A currency
     * not counted in hundredths is refused outright (whyNotChoosable()).
     *
     * The sentence names no box: a caller with several puts the box's name in
     * front ("Purchase cost: …"). It uses nothing but PHP.
     */
    public static function parseAmount(string $typed, string $currency, int $max = self::INT_COLUMN_MAX, bool $allowNegative = false, bool $fromFile = false): int|string
    {
        $code = strtoupper(trim($currency));
        if (isset(self::NOT_HUNDREDTHS[$code]) === true) {
            return self::whyNotChoosable($code);
        }
        $text = trim($typed);
        if ($text === '') {
            return self::REFUSED_EMPTY;
        }

        // ➖ A leading minus. In a file, the record's code, or a sign read for
        //    it, may sit either side of it: "-£3.00" and "£-3.00". The longest prefix is
        //    tried first, so "CA$" before "$". A typed box takes no sign at all.
        $negative = false;
        if (str_starts_with($text, '-') === true) {
            $negative = true;
            $text     = substr($text, 1);
        }
        if ($fromFile === true && $code !== '') {
            // The code, or a symbol sign this currency is written with (D-165):
            // "$" is the organisation's own dollars, whichever dollars they are.
            // A letters-only sign is not read (D-177). Anything else is refused
            // below.
            $taken = self::ownMarkerAtStart($text, $code);
            if ($taken !== '') {
                $text = ltrim(substr($text, strlen($taken)), ' ');
                if ($negative === false && str_starts_with($text, '-') === true) {
                    $negative = true;
                    $text     = substr($text, 1);
                }
                // TWO currency markers in front: one of this organisation's,
                // then a second — whatever ownMarkerAtStart(), then
                // anyMarkerAtStart(), finds in what follows. After its CODE
                // that is a sign ("GBP £12.50", "GBP $12"), capitals joined to
                // one ("GBP US$12"), or a known code those capitals spell
                // ("GBP USD$12", "GBP ALL$5"); after one of its SIGNS, any
                // marker ("££12.50", "£GBP 12.50", "£$12", "£USD 12"). "GBP
                // USD 12" is NOT taken: its code is followed by a word, so the
                // cell could not be read. Certain, so said; round 8 called
                // "GBP $12" "another currency (GBP)".
                // "Put only one in front" ONLY when the second, on its own, is
                // one ownMarkerAtStart() reads as ours — the very function that
                // reads a single-marker cell, so the two answers cannot
                // disagree. Round 11 judged the second by isForeignMarker(),
                // which reads "US$" by its "$", so "CAD US$12" was told to keep
                // CAD in front of what may well be US dollars, while "US$12"
                // alone could not be read in CAD (round 12). Every other
                // second gets the convert sentence (rounds 10-12).
                $again = self::ownMarkerAtStart($text, $code);
                $ours  = $again !== '';
                if ($ours === false) {
                    $again = self::anyMarkerAtStart($text);
                }
                if ($again !== '') {
                    $twice = 'That amount has two currency markers in front (' . $taken . ' and ' . $again . ')';
                    if ($ours === false) {
                        return $twice . ', and ' . $again . ' is not read as ' . $code . ' here. ' . self::convertWords($code);
                    }

                    return $twice . '. Put only one in front, for example ' . $code . ' 12.50.';
                }
            }
        }

        // 🔍 What is left must be figures and at most one point (and, in a
        //    file, commas). Anything else is refused with the reason for ITS shape.
        $shape = $fromFile === true ? '/^[0-9,]*(\.[0-9]*)?$/D' : '/^[0-9]*(\.[0-9]*)?$/D';
        if (preg_match($shape, $text) !== 1) {
            return self::whyNotAnAmount($text, $code, $fromFile, $allowNegative);
        }
        $point    = strpos($text, '.');
        $whole    = $point === false ? $text : substr($text, 0, $point);
        $fraction = $point === false ? '' : substr($text, $point + 1);
        if (preg_match('/[0-9]/', $whole . $fraction) !== 1) {
            return $fromFile === true ? self::FILE_REASON_UNREADABLE . ' ' . self::fileAdvice($code) : self::REFUSED_NO_FIGURES;
        }
        if (str_contains($whole, ',') === true && preg_match('/^[1-9][0-9]{0,2}(,[0-9]{3})+$/D', $whole) !== 1) {
            return self::REFUSED_COMMAS;
        }
        if (strlen($fraction) > 2) {
            return self::REFUSED_TOO_PRECISE;
        }

        // 🧮 The whole number of hundredths, as TEXT: the figures before the
        //    point, then exactly two after it. Compared with the maximum as
        //    text too, so nothing is converted before it is known to fit.
        $figures = ltrim(str_replace(',', '', $whole) . str_pad($fraction, 2, '0'), '0');
        if ($figures === '') {
            $figures = '0';
        }
        if ($negative === true && $figures !== '0' && $allowNegative === false) {
            return self::REFUSED_NEGATIVE;
        }
        if (self::figuresAbove($figures, $max) === true) {
            return self::REFUSED_TOO_LARGE_START . self::format(max(0, $max), $code) . '.';
        }
        $hundredths = (int) $figures;

        return $negative === true ? -$hundredths : $hundredths;
    }

    /**
     * 🗣️ Why `$text` (what is left once a leading minus and, in a file, the
     * record's code or a sign read for it are taken off) is not an amount.
     * A TYPED box gets the reason for its shape: round 2 of #1004's check
     * found one sentence ("no commas, spaces, currency signs or letters")
     * given for "12.5.5", which holds none of those. A FILE gets a specific
     * reason only where it is certain (see parseAmount()), else
     * FILE_REASON_UNREADABLE with fileAdvice().
     */
    private static function whyNotAnAmount(string $text, string $code, bool $fromFile, bool $allowNegative): string
    {
        if ($fromFile === true) {
            $marker = self::anyMarkerAtStart($text);
            // No currency chosen, and the cell carries a marker in front: its
            // money cannot be known (D-139), and that is certain.
            if ($marker !== '' && $code === '') {
                return 'That amount is written with a currency (' . $marker . '), but no currency is chosen for this '
                    . 'organisation, so the portal cannot tell what money it is. Choose the organisation\'s currency in '
                    . 'Admin, Settings, Organisation, then import the file again.';
            }
            // A currency sign or code that is not read as this organisation's
            // currency here, in front: certain that THIS PORTAL does not read
            // it as ours, NOT certain whose it is ("$" is a dozen currencies),
            // so say the first and never the second, and never suggest putting
            // our code in front (rounds 10 and 11).
            if ($marker !== '' && self::isForeignMarker($marker, $code) === true) {
                return self::otherCurrencyWords($code);
            }
            // Only figures, points, commas and minus signs: the fault is in
            // their arrangement, and can be said for certain.
            if (preg_match('/^[0-9.,\-]+$/D', $text) === 1 && preg_match('/[0-9]/', $text) === 1) {
                if (str_contains($text, '-') === true) {
                    return $allowNegative === true
                        ? self::REFUSED_MINUS_PLACE
                        : self::FILE_REASON_MINUS_NOT_HERE . ' ' . self::fileAdvice($code);
                }
                if (substr_count($text, '.') > 1) {
                    return self::REFUSED_ONE_POINT;
                }
                // A comma after the decimal point ("1.234,50").
                return self::REFUSED_COMMAS;
            }
            // Anything else: never a guess at which currency it might be.
            return self::FILE_REASON_UNREADABLE . ' ' . self::fileAdvice($code);
        }
        if (preg_match('/^[0-9,]*\.?[0-9]*[eE][+-]?[0-9]+$/D', $text) === 1) {
            return self::REFUSED_POWER_OF_TEN;
        }
        if (preg_match('/[^0-9.+\-]/', $text) === 1) {
            return self::REFUSED_NOT_FIGURES;
        }
        if (str_contains($text, '+') === true) {
            return self::REFUSED_PLUS;
        }
        if (str_contains($text, '-') === true) {
            return $allowNegative === true ? self::REFUSED_MINUS_PLACE : self::REFUSED_MINUS_NOT_HERE;
        }
        if (substr_count($text, '.') > 1) {
            return self::REFUSED_ONE_POINT;
        }

        return self::REFUSED_COMMAS;
    }

    /**
     * 📝 How to write an amount in a FILE: figures, and commas only between
     * thousands if you like. The organisation's three-letter CODE is mentioned
     * ONLY as a way to confirm a figure that is ALREADY in that currency, and
     * the sentence says plainly never to put it in front of another
     * currency's amount: round 9's "and the code GBP in front if you like",
     * given to a cell reading "$12", invited relabelling dollars as pounds
     * (round 10). The CODE is mentioned rather than a sign because the code is
     * always read, for every currency, while many currencies have no sign at
     * all (D-165). With no currency chosen, no code is mentioned: a code would
     * be refused too.
     *
     * It ends exactly TWO file-mode refusals: FILE_REASON_UNREADABLE (every
     * refusal whose reason cannot be known for certain) and
     * FILE_REASON_MINUS_NOT_HERE. Every other file-mode refusal carries its
     * own complete instruction (more than two decimals, commas, more than one
     * point, a minus in the wrong place, too large, nothing entered, two
     * currency markers, a currency sign or code not ours, no currency chosen).
     */
    public static function fileAdvice(string $code): string
    {
        $code = strtoupper(trim($code));

        return $code !== ''
            ? 'Write it in figures, with commas only between thousands if you like, for example 1,234.56. '
                . 'The code ' . $code . ' may go in front of a figure that is already in ' . $code . ' (' . $code . ' 1,234.56), '
                . 'but never in front of an amount in another currency.'
            : 'Write it in figures, with commas only between thousands if you like, for example 1,234.56.';
    }

    /**
     * 💱 What a FILE's amount answers when it begins with a marker
     * anyMarkerAtStart() recognises that isForeignMarker() judges is not the
     * organisation's (round 10, D-165: refused "with the true reason"). Only
     * those cells: any other start could not be read (FILE_REASON_UNREADABLE),
     * a real currency sign the converter does not recognise included ("₫12").
     * The sentence is a claim about THIS PORTAL — "is not read as CUP here" —
     * true by construction. Round 10 said "that CUP does not use", a claim
     * about the WORLD, and it was untrue for signs a currency really is
     * written with that ICU's English data (SIGNS_OF) does not list for it:
     * "₱12" for the Cuban peso, "£12" for the Egyptian pound, "C$12" for the
     * Canadian dollar. Which currency it IS cannot be said for certain ("$" is
     * a dozen currencies, and a three-letter word can be a code by accident),
     * so it is not said. It tells the person to CONVERT, and never to put the
     * organisation's code in front: that would relabel a foreign figure as
     * ours.
     */
    public static function otherCurrencyWords(string $code): string
    {
        $code = strtoupper(trim($code));

        return 'That amount starts with a currency sign or code that is not read as ' . $code . ' here. ' . self::convertWords($code);
    }

    /** The instruction shared by otherCurrencyWords() and the two-markers refusal whose second marker is not ours. */
    private static function convertWords(string $code): string
    {
        return 'Only ' . $code . ' amounts are read here: if this figure is in another currency, convert it to ' . $code
            . ' first — do not just put ' . $code . ' in front of it.';
    }

    /**
     * Is a marker anyMarkerAtStart() found at the START of a cell one that is
     * NOT read as this organisation's currency here? (Asked for a single
     * marker only; a second marker is judged by ownMarkerAtStart() itself.)
     * Its own code and own signs (signsOf(): the entries of SIGNS_OF with a
     * symbol in them — letters-only entries are never read, D-177) are read
     * as it. Any other sign in that data is not — "CA$" in a US-dollar
     * organisation, although it ends in "$". Anything else anyMarkerAtStart()
     * can return is either another three-letter code or capitals joined to a
     * sign ("US$", "BR$"), and is judged by what follows its capitals: a code
     * leaves nothing, which is never one of our signs, so a code that is not
     * ours is foreign; "US$" is foreign in a sterling organisation, while in a
     * US-dollar one it is merely unread (could not be read), because "US$" is
     * not in the data and its "$" IS ours.
     *
     * Round 10 also had `|| self::isKnown($marker)` beside the data test. It
     * could never change the answer — a known code that is not ours reaches
     * the last line with nothing after its capitals, and is foreign there too
     * — so round 11 removed it as dead code.
     */
    private static function isForeignMarker(string $marker, string $code): bool
    {
        $own = self::signsOf($code);
        if ($marker === $code || in_array($marker, $own, true) === true) {
            return false;
        }
        if (in_array($marker, self::symbolSigns(), true) === true) {
            return true;
        }
        $symbol = (string) preg_replace('/^[A-Z]+/', '', $marker);

        return in_array($symbol, $own, true) === false;
    }

    /** Every sign with a symbol in it, from any currency's SIGNS_OF, longest first. @return list<string> */
    private static function symbolSigns(): array
    {
        $signs = [];
        foreach (self::SIGNS_OF as $list) {
            foreach ($list as $sign) {
                if (self::isLettersOnly($sign) === false) {
                    $signs[$sign] = true;
                }
            }
        }
        $signs = array_keys($signs);
        usort($signs, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return $signs;
    }

    /** The sign with a symbol in it, of ANY currency, that a text begins with, or ''. */
    private static function symbolSignAtStart(string $text): string
    {
        foreach (self::symbolSigns() as $sign) {
            if (str_starts_with($text, $sign) === true) {
                return $sign;
            }
        }

        return '';
    }

    /**
     * 💱 A currency marker of ANY currency that a text begins with, or '' —
     * asked when the organisation has NO currency chosen (to say so), when a
     * cell is refused (to say the sign or code is not ours), and after one of
     * our own markers (two markers). In this order:
     *   - a sign with a symbol in it ("$", "CA$", "€");
     *   - three capital letters the portal knows as a currency, followed —
     *     after any run of spaces — by an amount (a figure or a point, after
     *     at most one minus) or a sign ("GBP 12.50", "USD$5", "USD US$5");
     *   - one to three capital letters glued to a symbol sign ("US$12"), which
     *     ICU's English data does not hold as a sign but is plainly one.
     * Letters-only signs are never markers (D-177), and "ALL PAID", "ALL-IN" or
     * "TOP-UP 20" are not codes.
     */
    private static function anyMarkerAtStart(string $text): string
    {
        $sign = self::symbolSignAtStart($text);
        if ($sign !== '') {
            return $sign;
        }
        if (preg_match('/^([A-Z]{3}) *(.*)$/sDu', $text, $m) === 1 && self::isKnown($m[1]) === true
            && self::amountOrSignFollows($m[2]) === true) {
            return $m[1];
        }

        return self::lettersThenSymbolAtStart($text);
    }

    /** One to three capital letters glued to a symbol sign ("US$"), at the start, or ''. */
    private static function lettersThenSymbolAtStart(string $text): string
    {
        if (preg_match('/^([A-Z]{1,3})(.+)$/sDu', $text, $m) === 1) {
            $sign = self::symbolSignAtStart($m[2]);
            if ($sign !== '') {
                return $m[1] . $sign;
            }
        }

        return '';
    }

    /**
     * Does what follows a code begin an amount — a figure or a point, after at
     * most one minus — or a sign (a symbol sign, or capitals glued to one,
     * "US$")? So "GBP 12", "GBP -12", "GBP£12" and "GBP US$12" are codes,
     * while "TOP-UP 20", "ALL-IN" and "ALL PAID" are words.
     */
    private static function amountOrSignFollows(string $rest): bool
    {
        $after = str_starts_with($rest, '-') === true ? substr($rest, 1) : $rest;

        return preg_match('/^[0-9.]/', $after) === 1 || self::symbolSignAtStart($after) !== ''
            || self::lettersThenSymbolAtStart($after) !== '';
    }

    /**
     * 🏷️ The organisation's own marker a file cell begins with, or '': its CODE
     * where — after any run of SPACES — an amount (a figure or a point, after
     * at most one minus) or ANY sign follows (the last is then two markers,
     * which parseAmount() refuses), else one of its own symbol signs. A
     * letters-only sign is never a marker (D-177). Only spaces: "GBP\t12" is
     * not read (FILE_REASON_UNREADABLE), on purpose — a tab inside a cell is
     * not something a spreadsheet writes in front of an amount.
     */
    private static function ownMarkerAtStart(string $text, string $code): string
    {
        if (preg_match('/^' . preg_quote($code, '/') . ' *(.*)$/sDu', $text, $m) === 1
            && self::amountOrSignFollows($m[1]) === true) {
            return $code;
        }
        foreach (self::signsOf($code) as $sign) {
            if (str_starts_with($text, $sign) === true) {
                return $sign;
            }
        }

        return '';
    }

    /** Is a sign made only of letters, with at most a trailing point — "R", "kr", "Rs", "Fr.", "Cg."? (D-177) */
    private static function isLettersOnly(string $sign): bool
    {
        return preg_match('/^\p{L}+\.?$/u', $sign) === 1;
    }

    /**
     * 🔢 A whole number of hundredths sent by a PROGRAM (a JSON API's `…Pence`
     * field), checked as strictly as parseAmount() checks typed text: a JSON
     * whole number or a string of figures, from 0 to `$max`. Answers the int,
     * or the end of a sentence saying why it was refused (the caller puts the
     * field's name in front). A JSON number with a fraction or an exponent
     * (899.5, 1e3) is REFUSED: `(int)` used to cut 899.5 to 899 without a
     * word, and to wrap anything past PHP's integer range.
     */
    public static function parseHundredths(mixed $value, int $max = self::INT_COLUMN_MAX): int|string
    {
        if (is_int($value) === true) {
            if ($value < 0) {
                return 'cannot be negative.';
            }
            $figures = (string) $value;
        } elseif (is_string($value) === true && preg_match('/^[0-9]+$/D', trim($value)) === 1) {
            $figures = ltrim(trim($value), '0');
            $figures = $figures === '' ? '0' : $figures;
        } else {
            return 'must be a whole number of hundredths (for example 1250 for 12.50), 0 or more, with no decimal point.';
        }
        if (self::figuresAbove($figures, $max) === true) {
            return 'is too large: the most that can be stored is ' . max(0, $max) . '.';
        }

        return (int) $figures;
    }

    /**
     * 🔢 An amount read back from a DECIMAL column ("123.45", or a SUM of
     * them), as a whole number of hundredths, through the same converter, so
     * no money becomes a float on its way to a comparison. The database has
     * already bounded the value, so a refusal here means it is not something a
     * DECIMAL column returns, and this THROWS rather than inventing a number.
     * NULL answers 0; a caller that must tell the two apart tests for null.
     */
    public static function storedDecimalToHundredths(string|int|null $stored): int
    {
        if ($stored === null) {
            return 0;
        }
        $answer = self::parseAmount((string) $stored, '', PHP_INT_MAX, true);
        if (is_string($answer) === true) {
            throw new \UnexpectedValueException('A stored amount could not be read as money: ' . $answer);
        }

        return $answer;
    }

    /**
     * 🔢 Hundredths written as the text a DECIMAL(…,2) column takes:
     * 123450 → "1234.50", -5 → "-0.05". Whole-number arithmetic only, so it
     * is exact at any size.
     */
    public static function hundredthsToDecimal(int $hundredths): string
    {
        $sign = $hundredths < 0 ? '-' : '';
        $abs  = abs($hundredths);

        return $sign . intdiv($abs, 100) . '.' . str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    /** Is a string of figures (no sign, no leading zeros) larger than $max? Compared as text, so exact at any length. */
    private static function figuresAbove(string $figures, int $max): bool
    {
        $limit = (string) max(0, $max);

        return strlen($figures) > strlen($limit) || (strlen($figures) === strlen($limit) && strcmp($figures, $limit) > 0);
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
     * ➕ Add an amount to a set of totals kept PER CURRENCY (D-139): totals are
     * a map of currency code => hundredths. Two currencies are never added
     * together; an unrecorded currency ('') is its own line.
     *
     * @param array<string, int> $totals
     */
    public static function addTo(array &$totals, string $code, int $hundredths): void
    {
        $code = strtoupper(trim($code));
        $totals[$code] = ($totals[$code] ?? 0) + $hundredths;
    }

    /**
     * 🧾 Per-currency totals written for a person, one per currency, in code
     * order: "€1,234.50", or "€1,234.50 and £20.00". With no totals at all it
     * is one line of 0.00, in `$emptyCurrency` (a plain "0.00" when that is '').
     *
     * @param array<string, int> $totals
     * @return list<string> One line per currency.
     */
    public static function totalsLines(array $totals, string $emptyCurrency = ''): array
    {
        if ($totals === []) {
            return [self::format(0, $emptyCurrency)];
        }
        ksort($totals, SORT_STRING);
        $lines = [];
        foreach ($totals as $code => $hundredths) {
            $lines[] = self::format((int) $hundredths, (string) $code);
        }

        return $lines;
    }

    /**
     * 🧾 totalsLines() ready for a page: each line escaped for HTML, joined by
     * a line break, so a total in two currencies reads as two lines.
     *
     * @param array<string, int> $totals
     */
    public static function totalsHtml(array $totals, string $emptyCurrency = ''): string
    {
        return implode('<br>', array_map(
            static fn (string $line): string => htmlspecialchars($line, ENT_QUOTES, 'UTF-8'),
            self::totalsLines($totals, $emptyCurrency)
        ));
    }

    /** 🧾 totalsLines() joined into one phrase: "€1,234.50 and £20.00". @param array<string, int> $totals */
    public static function totalsWords(array $totals, string $emptyCurrency = ''): string
    {
        $lines = self::totalsLines($totals, $emptyCurrency);
        if (count($lines) === 1) {
            return $lines[0];
        }
        $last = array_pop($lines);

        return implode(', ', $lines) . ' and ' . $last;
    }

    /**
     * 🏷️ How many of this organisation's money rows were saved with NO currency
     * (''), across OWN_TABLES and, through their parents, CHILD_TABLES. Null
     * when it could not be counted (the page then says so rather than "none").
     */
    public static function unlabelledCount(\mysqli $db, int $siteId): ?int
    {
        $count = 0;
        try {
            foreach (self::OWN_TABLES as $table) {
                $stmt = $db->prepare('SELECT COUNT(*) FROM `' . $table . '` WHERE `siteID` = ? AND `currency` = \'\'');
                $stmt->bind_param('i', $siteId);
                $stmt->execute();
                $count += (int) ($stmt->get_result()->fetch_row()[0] ?? 0);
                $stmt->close();
            }
            foreach (self::CHILD_TABLES as $child => [$parent, $col, $key]) {
                $stmt = $db->prepare('SELECT COUNT(*) FROM `' . $child . '` `k` JOIN `' . $parent . '` `p` ON `p`.`' . $key . '` = `k`.`' . $col . '` '
                    . 'WHERE `p`.`siteID` = ? AND `k`.`currency` = \'\'');
                $stmt->bind_param('i', $siteId);
                $stmt->execute();
                $count += (int) ($stmt->get_result()->fetch_row()[0] ?? 0);
                $stmt->close();
            }
        } catch (\Throwable $e) {
            return null;
        }

        return $count;
    }

    /**
     * 🏷️ Label this organisation's unlabelled money rows with `$code`, chosen by
     * an administrator who knows what money they were. Rows of its own tables
     * take `$code`; a child row takes its PARENT's currency (labelled first),
     * so a repair stays in its asset's money. An expense line's quoted currency
     * is labelled too when it is empty. Rows that already hold a currency are
     * never touched. All or nothing, in one transaction. Answers the number of
     * rows labelled, or null when it failed (nothing was changed).
     */
    public static function labelUnlabelled(\mysqli $db, int $siteId, string $code): ?int
    {
        if (self::isChoosable($code) === false) {
            return null;
        }
        $labelled = 0;
        $db->begin_transaction();
        try {
            foreach (self::OWN_TABLES as $table) {
                $stmt = $db->prepare('UPDATE `' . $table . '` SET `currency` = ? WHERE `siteID` = ? AND `currency` = \'\'');
                $stmt->bind_param('si', $code, $siteId);
                $stmt->execute();
                $labelled += $stmt->affected_rows;
                $stmt->close();
            }
            foreach (self::CHILD_TABLES as $child => [$parent, $col, $key]) {
                $stmt = $db->prepare('UPDATE `' . $child . '` `k` JOIN `' . $parent . '` `p` ON `p`.`' . $key . '` = `k`.`' . $col . '` '
                    . 'SET `k`.`currency` = IF(`p`.`currency` <> \'\', `p`.`currency`, ?) WHERE `p`.`siteID` = ? AND `k`.`currency` = \'\'');
                $stmt->bind_param('si', $code, $siteId);
                $stmt->execute();
                $labelled += $stmt->affected_rows;
                $stmt->close();
            }
            $stmt = $db->prepare('UPDATE `tblExpenseClaimItems` `k` JOIN `tblExpenseClaims` `p` ON `p`.`claimID` = `k`.`claimID` '
                . 'SET `k`.`quotedCurrency` = `k`.`currency` WHERE `p`.`siteID` = ? AND `k`.`quotedCurrency` = \'\' AND `k`.`currency` <> \'\'');
            $stmt->bind_param('i', $siteId);
            $stmt->execute();
            $stmt->close();
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();

            return null;
        }

        return $labelled;
    }

    /**
     * 🔒 Does the general Settings editor refuse this key? Yes for the currency:
     * that editor would store any text at all, where the organisation settings
     * page accepts only a currency the portal can store, and offers to label
     * old unlabelled amounts. That page is the ONE place it is changed.
     * Capitals are ignored, as a key's are everywhere.
     */
    public static function lockedInGeneralEditor(string $key): bool
    {
        return strcasecmp($key, self::SETTING) === 0;
    }

    /** What the general settings editor says when it refuses the currency. */
    public const LOCKED_MESSAGE = 'The currency is changed at Admin, Settings, Organisation, which accepts only a '
        . 'currency the portal can use. Nothing was changed.';

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
