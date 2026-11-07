<?php
// Path: _core/Translation.php
/**
 * -----------------------------------------------------------------------------
 * User-content translation + provider abstraction 🌐
 * -----------------------------------------------------------------------------
 * Translate the organisation's own published words — an announcement's body,
 * a project's description and a project update's content, the three entries
 * in ContentTranslation::SOURCES — via a pluggable provider. (This line used
 * to say "prayer requests, announcements, praise reports"; a prayer request is
 * never translated, D-049, and SOURCES names no praise report either — #830.)
 *
 *   translation.provider = anthropic | openai | google | deepl | libre
 *
 * This is DYNAMIC content translation, distinct from the portal's static
 * UI strings under web/_lang/*.php (which are human-translated).
 *
 * Translations are cached, one row per (sourceTable, sourceID, sourceField,
 * targetLanguage), together with a fingerprint of the exact words each was
 * made from (#799, migration 256). Translating the same announcement into
 * Welsh twice is answered from the cache the second time; translating it
 * after its words were EDITED is a new request, counted against both limits
 * below, and its answer replaces the old one. Until #799 the header said the
 * cache lasted "until content edited", which nothing implemented: an edited
 * piece kept its old words' translation for ever. (The example here used to
 * be a prayer request, which is never translated — D-049.)
 *
 * TWO monthly limits, per organisation, stop new provider calls once either
 * is reached; cached lookups still serve (#762, D-059):
 *
 *   translation.monthCapPence  money, as each provider REPORTS it;
 *   translation.monthCallCap   the NUMBER of requests sent to the provider,
 *                              which works whatever a provider reports.
 *
 * Both are counted in `tblTranslationUsage`, one row per organisation per
 * calendar month in UTC, written at the moment a provider call is made — not
 * read back out of the cache (see `monthUsage()` for why).
 *
 * @package   Portal\Core
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/278
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

// ⚠️ EVERY BUILT-IN FUNCTION AND CONSTANT IN THIS FILE IS WRITTEN WITH A
//    LEADING `\` (`\ceil()`, `\curl_exec()`, `\CURLOPT_POST`), AND THAT IS
//    LOAD-BEARING, NOT STYLE (#762 round 6). Inside a namespace PHP looks an
//    unqualified function or constant up in THIS namespace first, so one line
//    in ANY other `Portal\Core` file — `function ceil(...)` returning 0 — would
//    have zeroed every paid provider's recorded cost while this file, its
//    pin and every test stayed green; measured, 2400 pence became 0. With the
//    `\` written, the name can only mean PHP's own, and the token pin
//    (`tools/audit-checks/check_translation_engine_pinned.py`) protects the
//    `\` itself; that gate also refuses an unqualified built-in, so a new
//    call cannot quietly reopen this.

class Translation
{
    /**
     * 💸 The monthly spend cap's built-in default and hard maximum, in pence.
     *
     * The default matches what `full_schema.sql` seeds (`5000`, i.e. £50) and
     * is here as well because a reader must have an answer on an installation
     * whose row is missing — which is the state between files reaching a
     * server and somebody pressing "Run migrations".
     *
     * The hard maximum is £1,000 a month. Nothing may ever resolve above it,
     * whichever level asked.
     */
    public const DEFAULT_CAP_PENCE = 5000;
    public const MAX_CAP_PENCE     = 100000;

    /**
     * 🔢 The monthly limit on the NUMBER of requests sent to the provider —
     *    its built-in default and its hard maximum (#762, D-059).
     *
     * WHY THERE IS A SECOND LIMIT AT ALL. The money limit compares what each
     * provider REPORTS. LibreTranslate reports nothing (`libreTranslate()`
     * answers a cost of 0, always), so on that route the money limit could
     * never stop anything — an organisation on a limit of £1 could translate
     * without end — and `translation.libre.baseUrl` ships pointing at
     * libretranslate.com, which charges. The owner chose to count the calls as
     * well (D-059) rather than invent a price per call: a budget measured in
     * money that is not the real money is a budget somebody will plan against.
     *
     * WHY 1,000 — roughly TEN TIMES ORDINARY USE, AND A STOP ON RUNAWAY USE.
     * A provider call happens only the FIRST time a piece is put into a
     * language — after that the cache answers and nothing is sent — so
     * ordinary use is bounded by "pieces published this month" times
     * "languages members read in". A congregation publishing a few
     * announcements a week, read in two or three languages, makes tens of
     * calls a month, not hundreds.
     *
     * WHICH LIMIT BITES FIRST DEPENDS ON THE PROVIDER, measured with this
     * file's own price formulas for an announcement of about 1,000 characters
     * (about 300 tokens in and 250 out). An earlier version of this comment
     * said the money limit "stays the one that normally bites" and that 1,000
     * calls cost "of the order of £10" everywhere; both were wrong, and an
     * independent check measured it:
     *
     *   Google, DeepL      ~1p a call   1,000 calls ~£10   → the COUNT bites
     *                                                         first, long
     *                                                         before the £50
     *                                                         money default
     *                                                         (which would
     *                                                         allow ~5,000)
     *   Anthropic          ~13p a call  £50 ≈ 385 calls    → MONEY bites first
     *   OpenAI             ~20p a call  £50 ≈ 250 calls    → MONEY bites first
     *   LibreTranslate     reports 0p                      → only the COUNT
     *                                                         can bite
     *
     * So on Google and DeepL this default is the binding limit, and a customer
     * there gets about a fifth of what the money default alone would have
     * allowed. That is accepted on purpose: 1,000 is still ten times ordinary
     * use, an administrator who needs more raises it on the same page, and no
     * customer installation exists yet, so nobody loses anything they had.
     *
     * WHY 100,000 AS THE HARD MAXIMUM. Not a judgement about use; it is there so
     * a mistyped figure cannot become an unbounded bill, exactly as
     * `MAX_CAP_PENCE` is for money. "No limit" is 0, deliberately chosen, and is
     * the LARGEST value (#668's zero trap) — so it is allowed only where the
     * installation itself allows no limit, which `SettingLimit` decides.
     */
    public const DEFAULT_CALL_CAP = 1000;
    public const MAX_CALL_CAP     = 100000;

    /**
     * Why the last `translate()` in this request answered false, as one word,
     * so a caller can say WHICH limit stopped it (D-059) without working it out
     * again from a fresh read — a fresh read cannot tell "the provider failed on
     * the call that used the last slot" from "the slot was already gone", and
     * saying "the limit is reached" when the service merely failed is a false
     * statement about the customer's money.
     *
     *   ''           nothing refused (or translate() has not run)
     *   'off'        translation is switched off
     *   'not-set-up' the provider is unknown or has no key, so nothing could
     *                be sent and nothing was counted
     *   'money'      the month's money limit is reached
     *   'calls'      the month's limit on the number of requests is reached
     *   'unreadable' this month's use could not be read or recorded, so
     *                nothing was sent: on a money path, "we could not tell"
     *                must never become "go ahead"
     *   'provider'   the provider was asked and did not give a usable answer
     */
    private static string $lastRefusal = '';

    /**
     * Translate `text` from `sourceLang` to `targetLang`, persisting the
     * result in tblContentTranslation. Returns the translated string, or
     * null if the source is already in the target language, or false on
     * provider/cap failure.
     *
     * Callers tag a `sourceTable + sourceID + sourceField` triple so the
     * cache can find the piece again. That triple says WHICH piece, never
     * WHAT IT SAYS, so since #799 the cache also checks the words themselves:
     * see `sourceFingerprint()`.
     */
    public static function translate(
        string $sourceTable,
        int $sourceID,
        string $sourceField,
        string $sourceLang,
        string $targetLang,
        string $text
    ): string|false|null {
        self::$lastRefusal = '';
        $sourceLang = self::normaliseLocale($sourceLang);
        $targetLang = self::normaliseLocale($targetLang);
        if ($sourceLang === $targetLang) {
            return null;
        }
        if (\trim($text) === '') {
            return null;
        }

        // ⛔ IS THE FEATURE ON — ASKED BEFORE THE CACHE IS READ, and that order
        //    changed on 4 November 2026 (#485). It used to serve a cached
        //    translation whatever the setting said, so switching translation
        //    off left every piece already translated still showing a
        //    translation. An administrator switching it off means "stop", and
        //    a cache that keeps answering is not stopping.
        //
        //    The CAP is different and stays BELOW the cache on purpose: it is
        //    about spending money, and reading something already paid for
        //    spends nothing. The class comment has always said so.
        $settings = App::settings()['translation'] ?? [];
        if ((string) ($settings['enabled'] ?? '0') !== '1') {
            self::$lastRefusal = 'off';
            return false;
        }

        // 🔏 WORKED OUT ONCE, and used for both the look-up and the store
        //    (#799), so the two can never fingerprint different things. Before
        //    #799 the cache was found by which piece it was and nothing else,
        //    so an edited announcement kept being answered with the
        //    translation of its OLD words, for ever. A cached row now answers
        //    only while its fingerprint matches the words being asked about
        //    NOW; otherwise it is a miss, and the fresh request goes through
        //    every limit below exactly like any other.
        $fingerprint = self::sourceFingerprint($text);
        $cached = self::lookupCached($sourceTable, $sourceID, $sourceField, $targetLang, $fingerprint);
        if ($cached !== null) {
            return $cached;
        }

        // 💰 The monthly cap, and this is the one place it is enforced: HERE,
        //    where the money is about to be spent. D-049 made that a
        //    requirement rather than a nicety, quoting #668 — a save-time cap
        //    is not a cap, because a figure written by hand, restored from a
        //    backup, or stored before any refusal existed is still obeyed
        //    unless the SPENDER caps as well.
        //
        //    `capPence()` is what holds the organisation to what the
        //    installation allows (D-013): the two administrators may be
        //    different parties, so an organisation may hold itself to less and
        //    may never allow itself more.
        //
        //    🔢 AND THE COUNT (#762, D-059), asked SECOND and by a different
        //    mechanism, for two reasons that are both load-bearing:
        //
        //    • Money can only be checked before the call and added after it,
        //      because nobody knows the cost until the provider answers. The
        //      count CAN be taken before the call, as one atomic statement
        //      (`reserveCall()`), so two presses at the same moment cannot both
        //      slip under it. That is why the count is the one that holds under
        //      concurrency and the money is the one that may overshoot by one
        //      call's cost per simultaneous press.
        //    • Money goes first so a refusal on money uses up no slot.
        //
        //    🧾 BOTH ARE READ FROM `tblTranslationUsage`, NOT FROM THE CACHE.
        //    See `monthUsage()`: counting cache rows would count PIECES, not
        //    CALLS, and the difference is real today and grew when #799 made
        //    an edited announcement translate afresh into the SAME row.
        //
        //    ⛔ AND "COULD NOT READ" REFUSES. `monthSpendPence()` used to answer
        //    0 when its statement could not be prepared — on a money path that
        //    is "we could not tell" becoming "nothing spent, go ahead". Under
        //    this portal's strict mysqli reporting the branch was unreachable
        //    in a web request (the prepare throws, so the request died with a
        //    bare 500 before any call), so it failed CLOSED by accident; it is
        //    closed on purpose now, with a reason the member is told.
        // 🔌 IS THERE ANYBODY TO SEND IT TO — asked BEFORE anything is
        //    counted (#762, independent check). With no key, or a provider name
        //    this class does not know, the provider methods answer null WITHOUT
        //    contacting anybody. Asked after the reservation, that used a slot
        //    and the admin page called it "sent", so a portal switched on
        //    before its key was typed in exhausted its month's limit on
        //    requests that never left the server. Nothing is counted here,
        //    because nothing was sent.
        $provider = (string) ($settings['provider'] ?? 'anthropic');
        if (self::providerReady($provider, $settings) === false) {
            self::$lastRefusal = 'not-set-up';
            return false;
        }

        $siteId = Site::id();
        $month  = self::usageMonth();
        $usage  = self::monthUsage($siteId, $month);
        if ($usage === null) {
            self::$lastRefusal = 'unreadable';
            return false;
        }
        if (self::moneyReached($usage['pence']) === true) {
            self::$lastRefusal = 'money';
            return false;
        }
        $reserved = self::reserveCall($siteId, $month, self::callCap());
        if ($reserved !== 'reserved') {
            self::$lastRefusal = $reserved === 'full' ? 'calls' : 'unreadable';
            return false;
        }

        // 📞 FROM HERE A REQUEST IS SENT, AND IT IS COUNTED WHETHER OR NOT IT
        //    SUCCEEDS, on purpose. `providerReady()` above means the request
        //    really goes out (a key is there, a provider is known), so a failure
        //    from here is the company refusing, timing out, or answering
        //    something unusable — and a request that reached it may be billed.
        //    A provider that fails every time (a wrong key, a server that
        //    refuses) would otherwise be called without end on a limit that
        //    never moves. Allowing less is recoverable; allowing more is not.
        //    The cost, said plainly: a provider broken for a while uses up
        //    slots, and an administrator may have to raise the figure for the
        //    rest of the month once it is fixed — the admin page says which
        //    limit is reached so they know which to raise. (A connection that
        //    fails before reaching the company at all — a mistyped address —
        //    also counts: the portal cannot tell that apart from a timeout
        //    after the company accepted the request.)
        $result = self::providerTranslate($provider, $settings, $text, $sourceLang, $targetLang);
        if ($result === null) {
            self::$lastRefusal = 'provider';
            return false;
        }

        // 💷 The text is RETURNED by `settle()`, which is also what records the
        //    cost, so the two cannot come apart: removing the call removes the
        //    translation as well, and every test that translates anything fails.
        return self::settle($siteId, $month, $result, $sourceTable, $sourceID, $sourceField, $sourceLang, $targetLang, $provider, $fingerprint);
    }

    /**
     * 💷 What happens after a provider ANSWERED: add its reported cost to this
     *    month's record, keep the translation in the cache, and hand the words
     *    back.
     *
     * One method on purpose (#762, independent check): when recording the
     * cost was a separate line in `translate()`, deleting it left every test
     * green — the only route a test can drive reports a cost of 0 — and the
     * money limit then never moved on any paid provider. Now the words come
     * back THROUGH this method, so the translation path cannot skip it, and
     * the self-test drives this method directly with a cost above 0.
     *
     * @param array{text:string, costPence:int} $result      What the provider answered.
     * @param string                             $fingerprint `sourceFingerprint()` of the words that were
     *                                                        sent, stored so the cache knows them (#799).
     */
    private static function settle(int $siteId, string $month, array $result, string $sourceTable, int $sourceID,
                                   string $sourceField, string $sourceLang, string $targetLang, string $provider,
                                   string $fingerprint): string
    {
        self::recordSpend($siteId, $month, (int) $result['costPence']);
        self::persist($sourceTable, $sourceID, $sourceField, $sourceLang, $targetLang, (string) $result['text'], $provider, (int) $result['costPence'], $fingerprint);
        return (string) $result['text'];
    }

    /**
     * 🔌 Would a request to this provider actually be SENT? (#762)
     *
     * True when the provider is one this class knows AND what it needs to make
     * a request is there: an API key for the four paid companies, an address
     * for LibreTranslate (whose key is optional). False means the provider
     * method would answer null without contacting anybody, so nothing may be
     * counted. **These conditions must match the provider methods below** —
     * each of them still tests its own key first, as defence in depth, and if
     * one ever needs something new before it can send, add it here too.
     *
     * @param string               $provider The provider name from the settings.
     * @param array<string, mixed> $settings The `translation` settings group.
     */
    public static function providerReady(string $provider, array $settings): bool
    {
        switch ($provider) {
            case 'anthropic':
            case 'openai':
            case 'google':
            case 'deepl':
                return (string) ($settings[$provider]['apiKey'] ?? '') !== '';
            case 'libre':
                return \rtrim((string) ($settings['libre']['baseUrl'] ?? 'https://libretranslate.com'), '/') !== '';
            default:
                return false;
        }
    }

    /**
     * 💰 Is this month's MONEY limit reached, for the organisation being
     *    served? The ONE comparison, asked by `translate()` and by the admin
     *    page, so the page cannot say "reached" while a press still sends.
     *    `>=`, because a limit of £50 is reached AT £50.
     */
    public static function moneyReached(int $pence): bool
    {
        $cap = self::capPence();
        return $cap > 0 && $pence >= $cap;
    }

    /**
     * 🔢 Is this month's limit on the NUMBER of requests reached? For the admin
     *    page. `translate()` does not ask this: it takes a slot with
     *    `reserveCall()`, whose condition `calls < cap` is the same rule
     *    written as one atomic statement.
     */
    public static function callsReached(int $calls): bool
    {
        $cap = self::callCap();
        return $cap > 0 && $calls >= $cap;
    }

    /**
     * Cheap heuristic language detection for short bodies — checks
     * frequent stop-words and a few diacritic markers. Returns a 2-letter
     * code or 'en' on no signal. Caller can also pass the user's UI locale
     * as a tie-breaker.
     *
     * Not a substitute for a real detector; we use this on submit so the
     * cached `sourceLanguage` row is approximately right and we don't
     * waste API calls translating same-language content.
     */
    public static function detect(string $text, string $fallback = 'en'): string
    {
        $sample = \mb_strtolower(\mb_substr($text, 0, 600));
        $signals = [
            'es' => [' el ', ' la ', ' que ', ' por ', ' para ', ' como ', ' con ', ' una ', ' ¿', ' ¡', 'ñ'],
            'fr' => [' le ', ' la ', ' les ', ' que ', ' pour ', ' avec ', ' une ', ' nous ', ' vous ', 'ç', 'œ'],
            'de' => [' der ', ' die ', ' das ', ' und ', ' nicht ', ' ist ', ' sich ', 'ß', 'ä', 'ö', 'ü'],
            'pt' => [' que ', ' não ', ' para ', ' uma ', ' com ', ' você ', 'ã', 'õ', 'ç'],
            'it' => [' che ', ' della ', ' sono ', ' anche ', ' molto ', ' nostro '],
            'nl' => [' het ', ' een ', ' niet ', ' zijn ', ' van ', ' voor '],
            'cy' => [' yn ', ' y ', ' yr ', ' a ', ' o ', ' ar ', 'cymraeg', 'duw', 'ddim'],
            'pl' => [' się ', ' jest ', ' nie ', ' jak ', ' ale ', 'ł', 'ą', 'ę', 'ś'],
            'ro' => [' că ', ' nu ', ' este ', ' sunt ', ' care ', 'ș', 'ț', 'ă', 'î'],
            'en' => [' the ', ' and ', ' that ', ' have ', ' with ', ' from ', ' please ', ' god '],
        ];
        $best = $fallback;
        $bestScore = 0;
        foreach ($signals as $code => $needles) {
            $score = 0;
            foreach ($needles as $n) {
                $score += \substr_count($sample, $n);
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $code;
            }
        }
        return $bestScore > 0 ? $best : $fallback;
    }

    /**
     * Per-user auto-translate opt-in (used by callers wrapping content).
     */
    public static function userAutoTranslate(int $userId): bool
    {
        $db = App::db();
        $stmt = $db->prepare('SELECT autoTranslate FROM tblUserTranslationPref WHERE userID = ? LIMIT 1');
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->bind_result($flag);
        $hit = $stmt->fetch() === true;
        $stmt->close();
        return $hit === true && (int) $flag === 1;
    }

    /**
     * 💰 The most that may be spent on translation in a month, in pence — the
     *    ONE answer to that question.
     *
     * The LIMIT can differ by organisation, because `SettingLimit` lets an
     * organisation tighten the installation's figure, and the SPEND it is
     * compared against is per organisation too: #765 made it so, and since
     * #762 it is read from `tblTranslationUsage` by `monthUsage()`. (This
     * docblock said, until #762, that the spend was summed across the whole
     * installation — true when it was written, untrue after #765, and left
     * standing in the one place a reader of the limit would look.)
     *
     * THREE TIERS, each able to tighten the one above it and none to loosen it
     * (D-013): the hard maximum below, then the installation, then the
     * organisation. `SettingLimit` does the arithmetic in one place; do not do
     * it here and do not compare the two layers by hand.
     *
     * **ZERO MEANS "NO LIMIT", SO ZERO IS THE LARGEST VALUE AND NOT THE
     * SMALLEST.** That is #668's trap, and getting it wrong is costly in both
     * directions: read as the smallest, an installation that chose "no limit"
     * would refuse every organisation including a tighter one; read as a plain
     * number, an organisation could store 0 under an installation's £50 and
     * spend without end. `$zeroIsLargest` is how it is expressed rather than
     * converted by hand, because the rule — *"no limit is allowed only when the
     * installation itself allows no limit"* — cannot be written as arithmetic.
     *
     * `MAX_CAP_PENCE` is the hard maximum. It is not a judgement about what a
     * customer should spend; it is there so that a mistyped figure cannot
     * become a bill, and so that "no limit" is a decision somebody takes
     * rather than a number they typed badly.
     *
     * @return int Pence per calendar month, or 0 for no limit.
     */
    public static function capPence(): int
    {
        return SettingLimit::effective('translation.monthCapPence', self::DEFAULT_CAP_PENCE, self::MAX_CAP_PENCE, true);
    }

    /**
     * 🔢 The most provider requests allowed in a month — the ONE answer to that
     *    question (#762, D-059). The twin of `capPence()`, built the same way.
     *
     * Three tiers through `SettingLimit`, never compared by hand: the hard
     * maximum, then the installation, then the organisation, each able to
     * tighten the one above and none to loosen it (D-013). Zero means no
     * limit, so it is the LARGEST value (`$zeroIsLargest`), for exactly the
     * reasons written on `capPence()`.
     *
     * @return int Requests per calendar month (UTC), or 0 for no limit.
     */
    public static function callCap(): int
    {
        return SettingLimit::effective('translation.monthCallCap', self::DEFAULT_CALL_CAP, self::MAX_CALL_CAP, true);
    }

    /**
     * Why the last `translate()` in this request answered false. See the
     * property for the words. A handler asks this rather than re-reading the
     * figures, because only `translate()` knows which test actually refused.
     */
    public static function lastRefusal(): string
    {
        return self::$lastRefusal;
    }

    /**
     * 🗓️ The month a call made now is counted in: the first day of the current
     *    calendar month IN UTC, as `Y-m-01`.
     *
     * Worked out ONCE per `translate()` and handed to every statement, so the
     * read, the reservation and the spend all agree about which month it is
     * even across midnight on the last day. UTC because a moment is stored in
     * UTC here (the time-zone rule in `.claude/CLAUDE.md`), and because the
     * database's own `NOW()` is in whatever zone the server's session happens
     * to be — #783 is that exact fault elsewhere. The cost, said plainly: for
     * an organisation east of Greenwich the month turns over a few hours after
     * its own midnight, and west of it a few hours before.
     */
    public static function usageMonth(): string
    {
        return \gmdate('Y-m-01');
    }

    /**
     * 🧾 This organisation's use this month — requests made and pence reported
     *    — or NULL when it cannot be read.
     *
     * WHY A RECORD OF ITS OWN, AND NOT THE CACHE (measured, #762). The money
     * figure used to be `SUM(costPence)` over `tblContentTranslation`, the cache.
     * The cache holds ONE row per piece, field and language — `persist()` is an
     * upsert on that key — so it can only ever say how many PIECES were
     * translated, never how many CALLS were made. Today the two differ in three
     * ways:
     *
     *   • two presses of the same new piece at the same moment both miss the
     *     cache, both call the provider and both pay, and leave one row
     *     carrying the second call's cost;
     *   • a call that fails, or answers something unusable, writes no row at
     *     all, though it may have been billed (see `provider-failed`); and
     *   • an EDITED piece is translated afresh into the SAME row (#799,
     *     migration 256: a cached row answers only while its fingerprint
     *     matches the words now in the source), so every re-translation costs
     *     a request and leaves still one row. On 6 November 2026, when this
     *     was written, nothing refreshed a row at all and an edited
     *     announcement kept showing its OLD words' translation; this note
     *     predicted that the fix would make a count of cache rows fall behind
     *     the bill, and it does.
     *
     * So both limits are counted here, at the moment a call is made, one row
     * per organisation per month, and the cache is only a cache.
     *
     * @param int    $siteId The organisation being served.
     * @param string $month  `usageMonth()`.
     *
     * @return array{calls:int, pence:int}|null
     */
    public static function monthUsage(int $siteId, string $month): ?array
    {
        try {
            $stmt = App::db()->prepare(
                'SELECT `calls`, `costPence` FROM `tblTranslationUsage` '
                . 'WHERE `siteID` = ? AND `monthStartUtc` = ? LIMIT 1'
            );
            if ($stmt === false) {
                return null;
            }
            $stmt->bind_param('is', $siteId, $month);
            $stmt->execute();
            $stmt->bind_result($calls, $pence);
            $hit = $stmt->fetch() === true;
            $stmt->close();
        } catch (\Throwable $e) {
            // ⚠️ The usual cause is a server where the files have arrived and
            //    "Run migrations" has not been pressed, so the table is not
            //    there yet. Refusing is the recoverable direction; the admin
            //    page says the record could not be read.
            return null;
        }
        if ($hit === false) {
            return ['calls' => 0, 'pence' => 0];
        }
        return ['calls' => (int) $calls, 'pence' => (int) $pence];
    }

    /**
     * 🎟️ Take one request slot for this organisation this month, or say why not.
     *
     * ONE conditional `UPDATE` does the test and the count together, so two
     * presses at the same moment cannot both see "999 of 1,000" and both go
     * through: InnoDB takes the row lock, the second waits, then finds 1,000
     * and matches nothing. The row is made first if this is the month's first
     * call, by an insert that changes nothing when it is already there.
     *
     * `$cap` of 0 means no limit, and the slot is still counted, so the admin
     * page can say how many requests were made even where nothing limits them.
     *
     * @return string 'reserved', 'full', or 'unreadable' (nothing was counted
     *                and nothing may be sent).
     */
    private static function reserveCall(int $siteId, string $month, int $cap): string
    {
        try {
            $db = App::db();
            $make = $db->prepare(
                'INSERT INTO `tblTranslationUsage` (`siteID`, `monthStartUtc`, `calls`, `costPence`) '
                . 'VALUES (?, ?, 0, 0) ON DUPLICATE KEY UPDATE `calls` = `calls`'
            );
            if ($make === false) {
                return 'unreadable';
            }
            $make->bind_param('is', $siteId, $month);
            $make->execute();
            $make->close();

            $take = $db->prepare(
                'UPDATE `tblTranslationUsage` SET `calls` = `calls` + 1 '
                . 'WHERE `siteID` = ? AND `monthStartUtc` = ? AND (? = 0 OR `calls` < ?)'
            );
            if ($take === false) {
                return 'unreadable';
            }
            $take->bind_param('isii', $siteId, $month, $cap, $cap);
            $take->execute();
            $taken = $take->affected_rows;
            $take->close();
        } catch (\Throwable $e) {
            return 'unreadable';
        }
        // affected_rows is exactly 1 when the slot was taken: the row exists
        // (made just above) and `calls + 1` always changes it. Anything else —
        // 0 when the limit is reached, -1 on an error — sends nothing.
        return $taken === 1 ? 'reserved' : 'full';
    }

    /**
     * 💷 Add what a call that SUCCEEDED was reported to cost.
     *
     * Its slot was taken before the call, so only the money is added here. If
     * this write fails the translation is still returned — the provider has
     * already been paid and refusing the words would waste that — and the
     * failure is written to PHP's error log in fixed words, so an under-count
     * is at least visible to whoever reads that log. Never the fault's own
     * words: they can quote the statement and its values.
     */
    private static function recordSpend(int $siteId, string $month, int $costPence): void
    {
        if ($costPence <= 0) {
            return;
        }
        try {
            $stmt = App::db()->prepare(
                'UPDATE `tblTranslationUsage` SET `costPence` = `costPence` + ? '
                . 'WHERE `siteID` = ? AND `monthStartUtc` = ?'
            );
            if ($stmt === false) {
                \error_log('Translation: the cost of a translation could not be recorded; this month\'s spend is under-counted.');
                return;
            }
            $stmt->bind_param('iis', $costPence, $siteId, $month);
            $stmt->execute();
            $matched = $stmt->affected_rows;
            $stmt->close();
            // ⚠️ NO ROW MATCHED (#762 round 2). The reservation made this
            //    month's row moments ago, so this means it vanished in between
            //    — a restore in that window, say — and the spend was lost
            //    without any error. Logged in fixed words naming only the
            //    organisation, never the figure's context.
            if ($matched !== 1) {
                \error_log('Translation: the cost of a translation for organisation ' . $siteId
                    . ' could not be recorded because this month\'s usage row was not there; this month\'s spend is under-counted.');
            }
        } catch (\Throwable $e) {
            \error_log('Translation: the cost of a translation could not be recorded; this month\'s spend is under-counted.');
        }
    }

    // -------------------------------------------------------------------------
    // Providers — each returns ['text'=>string, 'costPence'=>int] or null.
    // -------------------------------------------------------------------------

    private static function providerTranslate(string $provider, array $settings, string $text, string $from, string $to): ?array
    {
        switch ($provider) {
            case 'anthropic':
                return self::anthropicTranslate($settings, $text, $from, $to);
            case 'openai':
                return self::openaiTranslate($settings, $text, $from, $to);
            case 'google':
                return self::googleTranslate($settings, $text, $from, $to);
            case 'deepl':
                return self::deeplTranslate($settings, $text, $from, $to);
            case 'libre':
                return self::libreTranslate($settings, $text, $from, $to);
            default:
                return null;
        }
    }

    /**
     * Anthropic Messages API. Small bilingual prompt — preserves
     * meaning, returns plain translated text with no preamble.
     *
     * @link https://docs.anthropic.com/en/api/messages
     */
    private static function anthropicTranslate(array $settings, string $text, string $from, string $to): ?array
    {
        $key   = (string) ($settings['anthropic']['apiKey'] ?? '');
        $model = (string) ($settings['anthropic']['model'] ?? 'claude-haiku-4-5-20251001');
        if ($key === '') {
            return null;
        }
        $payload = [
            'model'      => $model,
            'max_tokens' => 1024,
            'system'     => 'You translate user-generated text. Reply with ONLY the translated text — no preamble, no explanation, no quotation marks. Preserve line breaks and proper nouns.',
            'messages'   => [[
                'role'    => 'user',
                'content' => 'Translate from ' . $from . ' to ' . $to . ":\n\n" . $text,
            ]],
        ];
        $ch = \curl_init('https://api.anthropic.com/v1/messages');
        \curl_setopt($ch, \CURLOPT_RETURNTRANSFER, true);
        \curl_setopt($ch, \CURLOPT_POST, true);
        \curl_setopt($ch, \CURLOPT_POSTFIELDS, \json_encode($payload));
        \curl_setopt($ch, \CURLOPT_HTTPHEADER, [
            'x-api-key: ' . $key,
            'anthropic-version: 2023-06-01',
            'content-type: application/json',
        ]);
        \curl_setopt($ch, \CURLOPT_TIMEOUT, 30);
        $resp = \curl_exec($ch);
        $code = (int) \curl_getinfo($ch, \CURLINFO_HTTP_CODE);
        // No curl_close() here: the handle has been an object since PHP 8.0 and is
        // freed when it goes out of scope. The call has done nothing since then,
        // and PHP 8.5 raises a "deprecated" notice every time it runs (#673).
        if ($resp === false || $code < 200 || $code >= 300) {
            return null;
        }
        $body = \json_decode((string) $resp, true);
        if (\is_array($body) === false || isset($body['content'][0]['text']) === false) {
            return null;
        }
        $out = \trim((string) $body['content'][0]['text']);
        $inTok  = (int) ($body['usage']['input_tokens']  ?? 0);
        $outTok = (int) ($body['usage']['output_tokens'] ?? 0);
        // Haiku 4.5 list price ~$0.80 / $4 per MTok → tiny per call.
        $costPence = (int) \ceil((($inTok * 0.00008) + ($outTok * 0.0004)) * 100);
        return ['text' => $out, 'costPence' => \max(0, $costPence)];
    }

    /**
     * OpenAI Chat Completions (gpt-4o-mini default — cheap).
     *
     * @link https://platform.openai.com/docs/api-reference/chat
     */
    private static function openaiTranslate(array $settings, string $text, string $from, string $to): ?array
    {
        $key   = (string) ($settings['openai']['apiKey'] ?? '');
        $model = (string) ($settings['openai']['model'] ?? 'gpt-4o-mini');
        if ($key === '') {
            return null;
        }
        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => 'You translate user-generated text. Reply with ONLY the translated text — no preamble, no explanation, no quotation marks. Preserve line breaks.'],
                ['role' => 'user',   'content' => 'Translate from ' . $from . ' to ' . $to . ":\n\n" . $text],
            ],
            'temperature' => 0.2,
        ];
        $ch = \curl_init('https://api.openai.com/v1/chat/completions');
        \curl_setopt($ch, \CURLOPT_RETURNTRANSFER, true);
        \curl_setopt($ch, \CURLOPT_POST, true);
        \curl_setopt($ch, \CURLOPT_POSTFIELDS, \json_encode($payload));
        \curl_setopt($ch, \CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $key,
            'Content-Type: application/json',
        ]);
        \curl_setopt($ch, \CURLOPT_TIMEOUT, 30);
        $resp = \curl_exec($ch);
        $code = (int) \curl_getinfo($ch, \CURLINFO_HTTP_CODE);
        if ($resp === false || $code < 200 || $code >= 300) {
            return null;
        }
        $body = \json_decode((string) $resp, true);
        $out = \is_array($body) === true ? \trim((string) ($body['choices'][0]['message']['content'] ?? '')) : '';
        if ($out === '') {
            return null;
        }
        $inTok  = (int) ($body['usage']['prompt_tokens']     ?? 0);
        $outTok = (int) ($body['usage']['completion_tokens'] ?? 0);
        $costPence = (int) \ceil((($inTok * 0.00015) + ($outTok * 0.0006)) * 100);
        return ['text' => $out, 'costPence' => \max(0, $costPence)];
    }

    /**
     * Google Cloud Translation v2 (simple).
     *
     * @link https://cloud.google.com/translate/docs/reference/rest/v2/translate
     */
    private static function googleTranslate(array $settings, string $text, string $from, string $to): ?array
    {
        $key = (string) ($settings['google']['apiKey'] ?? '');
        if ($key === '') {
            return null;
        }
        $url = 'https://translation.googleapis.com/language/translate/v2?key=' . \rawurlencode($key);
        $ch = \curl_init($url);
        \curl_setopt($ch, \CURLOPT_RETURNTRANSFER, true);
        \curl_setopt($ch, \CURLOPT_POST, true);
        \curl_setopt($ch, \CURLOPT_POSTFIELDS, \http_build_query([
            'q'      => $text,
            'source' => $from,
            'target' => $to,
            'format' => 'text',
        ]));
        \curl_setopt($ch, \CURLOPT_TIMEOUT, 30);
        $resp = \curl_exec($ch);
        $code = (int) \curl_getinfo($ch, \CURLINFO_HTTP_CODE);
        if ($resp === false || $code < 200 || $code >= 300) {
            return null;
        }
        $body = \json_decode((string) $resp, true);
        $out = \is_array($body) === true ? (string) ($body['data']['translations'][0]['translatedText'] ?? '') : '';
        if ($out === '') {
            return null;
        }
        // Google charges per source char; ~$20/M → ~1.5p / 10k chars.
        $cost = (int) \ceil((\mb_strlen($text) / 10000) * 1.5);
        return ['text' => $out, 'costPence' => $cost];
    }

    /**
     * DeepL — best European-language quality.
     *
     * @link https://developers.deepl.com/docs/api-reference/translate
     */
    private static function deeplTranslate(array $settings, string $text, string $from, string $to): ?array
    {
        $key = (string) ($settings['deepl']['apiKey'] ?? '');
        if ($key === '') {
            return null;
        }
        // Free-tier keys end in ':fx' and use a different host.
        $host = \str_ends_with($key, ':fx') === true ? 'api-free.deepl.com' : 'api.deepl.com';
        $ch = \curl_init('https://' . $host . '/v2/translate');
        \curl_setopt($ch, \CURLOPT_RETURNTRANSFER, true);
        \curl_setopt($ch, \CURLOPT_POST, true);
        \curl_setopt($ch, \CURLOPT_POSTFIELDS, \http_build_query([
            'text'        => $text,
            'source_lang' => \strtoupper($from),
            'target_lang' => \strtoupper($to),
        ]));
        \curl_setopt($ch, \CURLOPT_HTTPHEADER, ['Authorization: DeepL-Auth-Key ' . $key]);
        \curl_setopt($ch, \CURLOPT_TIMEOUT, 30);
        $resp = \curl_exec($ch);
        $code = (int) \curl_getinfo($ch, \CURLINFO_HTTP_CODE);
        if ($resp === false || $code < 200 || $code >= 300) {
            return null;
        }
        $body = \json_decode((string) $resp, true);
        $out = \is_array($body) === true ? (string) ($body['translations'][0]['text'] ?? '') : '';
        if ($out === '') {
            return null;
        }
        $cost = (int) \ceil((\mb_strlen($text) / 10000) * 1.5);
        return ['text' => $out, 'costPence' => $cost];
    }

    /**
     * LibreTranslate (self-hosted or libretranslate.com).
     *
     * @link https://github.com/LibreTranslate/LibreTranslate
     */
    private static function libreTranslate(array $settings, string $text, string $from, string $to): ?array
    {
        $base = \rtrim((string) ($settings['libre']['baseUrl'] ?? 'https://libretranslate.com'), '/');
        $key  = (string) ($settings['libre']['apiKey'] ?? '');
        $payload = ['q' => $text, 'source' => $from, 'target' => $to, 'format' => 'text'];
        if ($key !== '') {
            $payload['api_key'] = $key;
        }
        $ch = \curl_init($base . '/translate');
        \curl_setopt($ch, \CURLOPT_RETURNTRANSFER, true);
        \curl_setopt($ch, \CURLOPT_POST, true);
        \curl_setopt($ch, \CURLOPT_POSTFIELDS, \json_encode($payload));
        \curl_setopt($ch, \CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        \curl_setopt($ch, \CURLOPT_TIMEOUT, 30);
        $resp = \curl_exec($ch);
        $code = (int) \curl_getinfo($ch, \CURLINFO_HTTP_CODE);
        if ($resp === false || $code < 200 || $code >= 300) {
            return null;
        }
        $body = \json_decode((string) $resp, true);
        $out = \is_array($body) === true ? (string) ($body['translatedText'] ?? '') : '';
        if ($out === '') {
            return null;
        }
        return ['text' => $out, 'costPence' => 0];
    }

    // -------------------------------------------------------------------------
    // Cache + helpers
    // -------------------------------------------------------------------------

    /**
     * 🔏 The fingerprint of the exact words a translation is made from (#799):
     *    SHA-256 of the text, as 64 lower-case hexadecimal characters.
     *
     * The text is the one `translate()` was given, which is the one sent to the
     * provider and, from the one caller, exactly what
     * `ContentTranslation::readSourceText()` read out of the row. It is NOT
     * trimmed or tidied first, deliberately: a translation is of exactly these
     * words, so any change to them — a corrected spelling included — is
     * translated afresh. Tidying would mean judging which changes "do not
     * count", and the one that turns out to matter would be served wrong.
     *
     * Not a secret and not a security check: it only has to change when the
     * words change, which SHA-256 does with no realistic exception.
     */
    private static function sourceFingerprint(string $text): string
    {
        return \hash('sha256', $text);
    }

    /**
     * The cached translation of this piece into this language, but ONLY while
     * it was made from the words being asked about now (#799).
     *
     * The fingerprint is compared in the statement itself, so a row made from
     * other words is never even read. A row with NO fingerprint — every row
     * cached before migration 256 — never matches either, because `NULL = ?`
     * is never true: nothing records which words such a row was made from, so
     * it is treated as stale and translated again rather than shown as
     * current. Migration 256's header says why, and what that costs.
     */
    private static function lookupCached(string $table, int $id, string $field, string $target, string $fingerprint): ?string
    {
        $db = App::db();
        $stmt = $db->prepare(
            'SELECT `translatedContent` FROM `tblContentTranslation` '
            . 'WHERE `sourceTable` = ? AND `sourceID` = ? AND `sourceField` = ? AND `targetLanguage` = ? '
            . 'AND `sourceHash` = ? LIMIT 1'
        );
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('sisss', $table, $id, $field, $target, $fingerprint);
        $stmt->execute();
        $stmt->bind_result($content);
        $hit = $stmt->fetch() === true;
        $stmt->close();
        return $hit === true ? (string) $content : null;
    }

    private static function persist(string $table, int $id, string $field, string $source, string $target, string $content,
                                    string $provider, int $cost, string $fingerprint): void
    {
        $db = App::db();
        // 🏢 THE ORGANISATION IS RECORDED, so its spending can be told apart
        //    from another's (#765, migration 250). Without it the monthly sum
        //    covered the whole installation while the BUDGET was per
        //    organisation, so a busy church exhausted a small one's allowance
        //    and the small one simply found translation had stopped.
        //
        //    `siteID` is set on a duplicate too: a row rewritten for a new
        //    translation of the same thing belongs to whoever owns it now.
        //
        //    🧾 SINCE #762 NEITHER LIMIT READS THIS TABLE. The month's spend and
        //    the month's number of requests are counted in `tblTranslationUsage`
        //    at the moment a call is made (see `monthUsage()` for why the cache
        //    cannot count calls). `costPence` and `siteID` are still written
        //    here as a record of what each cached piece cost and whose it was;
        //    nothing sums them any more, and nothing should start to.
        //
        //    🔏 THE FINGERPRINT IS WRITTEN ON A DUPLICATE TOO (#799), and that
        //    is the half that is easy to forget. A re-translation of an edited
        //    original lands here as a DUPLICATE of the old row (same piece,
        //    same language), so it REPLACES the old translation rather than
        //    keeping it beside it — and if the update clause left `sourceHash`
        //    alone, the row would go on carrying the OLD words' fingerprint,
        //    never match again, and every press after an edit would be a paid
        //    request for ever. `sourceLanguage` is refreshed for the same
        //    reason: an edit can change the language the original is in.
        $siteId = Site::id();
        $stmt = $db->prepare(
            'INSERT INTO `tblContentTranslation` (`sourceTable`, `sourceID`, `sourceField`, '
            . '`sourceLanguage`, `targetLanguage`, `translatedContent`, `sourceHash`, `provider`, `costPence`, '
            . '`siteID`) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE `translatedContent` = VALUES(`translatedContent`), '
            . '    `sourceHash` = VALUES(`sourceHash`), `sourceLanguage` = VALUES(`sourceLanguage`), '
            . '    `provider` = VALUES(`provider`), `costPence` = VALUES(`costPence`), '
            . '    `siteID` = VALUES(`siteID`), `translatedAt` = NOW()'
        );
        if ($stmt === false) {
            return;
        }
        // 🔤 TEN VALUES, TEN TYPES, IN ORDER: two `i` at the END (cost, then
        //    organisation) and none before them but the row number. Until #799
        //    this read `'sissssisi'`, which bound the PROVIDER as a number, so
        //    every cached row since the table was built (`8625b9e`) recorded
        //    its provider as `0` — measured on MySQL 8.0.36 on 7 November 2026.
        //    Nothing reads the column, so nothing showed it.
        $stmt->bind_param('sissssssii', $table, $id, $field, $source, $target, $content, $fingerprint, $provider, $cost, $siteId);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Normalise a locale (en-GB → en, EN → en, cy-cy → cy).
     */
    public static function normaliseLocale(string $locale): string
    {
        $bare = \strtolower(\trim($locale));
        if (\strpos($bare, '-') !== false) {
            $bare = \substr($bare, 0, \strpos($bare, '-'));
        }
        return $bare === '' ? 'en' : $bare;
    }
}
