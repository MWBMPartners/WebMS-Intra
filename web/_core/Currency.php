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
