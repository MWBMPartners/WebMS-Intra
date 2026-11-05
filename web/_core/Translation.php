<?php
// Path: _core/Translation.php
/**
 * -----------------------------------------------------------------------------
 * User-content translation + provider abstraction 🌐
 * -----------------------------------------------------------------------------
 * Auto-translate user-generated content (prayer requests, announcements,
 * praise reports, …) via a pluggable provider:
 *
 *   translation.provider = anthropic | openai | google | deepl | libre
 *
 * This is DYNAMIC content translation, distinct from the portal's static
 * UI strings under web/_lang/*.php (which are human-translated).
 *
 * Translations are cached forever (until content edited) keyed on
 * (sourceTable, sourceID, sourceField, targetLanguage). Translating the
 * same prayer request into Welsh twice hits cache the second time.
 *
 * A per-site monthly cost cap (translation.monthCapPence) blocks new API
 * calls once exceeded; cached lookups still serve.
 *
 * @package   Portal\Core
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/278
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

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
     * Translate `text` from `sourceLang` to `targetLang`, persisting the
     * result in tblContentTranslation. Returns the translated string, or
     * null if the source is already in the target language, or false on
     * provider/cap failure.
     *
     * Callers tag a `sourceTable + sourceID + sourceField` triple so the
     * cache is content-addressable.
     */
    public static function translate(
        string $sourceTable,
        int $sourceID,
        string $sourceField,
        string $sourceLang,
        string $targetLang,
        string $text
    ): string|false|null {
        $sourceLang = self::normaliseLocale($sourceLang);
        $targetLang = self::normaliseLocale($targetLang);
        if ($sourceLang === $targetLang) {
            return null;
        }
        if (trim($text) === '') {
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
            return false;
        }

        $cached = self::lookupCached($sourceTable, $sourceID, $sourceField, $targetLang);
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
        $cap = self::capPence();
        if ($cap > 0 && self::monthSpendPence() >= $cap) {
            return false;
        }

        $provider = (string) ($settings['provider'] ?? 'anthropic');
        $result = self::providerTranslate($provider, $settings, $text, $sourceLang, $targetLang);
        if ($result === null) {
            return false;
        }

        self::persist($sourceTable, $sourceID, $sourceField, $sourceLang, $targetLang, $result['text'], $provider, $result['costPence']);
        return $result['text'];
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
        $sample = mb_strtolower(mb_substr($text, 0, 600));
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
                $score += substr_count($sample, $n);
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
     * **DO NOT CALL THIS "THIS ORGANISATION'S" LIMIT WITHOUT A QUALIFIER, which
     * an earlier version of this docblock did.** The LIMIT can differ by
     * organisation, because `SettingLimit` lets an organisation tighten the
     * installation's figure. The SPEND it is compared against cannot:
     * `monthSpendPence()` sums `tblContentTranslation.costPence` across the
     * whole installation, because that table has no organisation column. So on
     * an installation serving several organisations, **one organisation's
     * spending counts against every organisation's limit** — recorded as #765
     * rather than fixed here, because fixing it needs a column and a migration
     * and the owner's decision about what to do with rows already there. The
     * class header's "a per-site monthly cost cap" has always had the same
     * problem.
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
     * Month-to-date spend in pence — **across EVERY organisation on this
     * installation**, because `tblContentTranslation` has no organisation
     * column to filter on.
     *
     * That is fine on a single-organisation installation and wrong on a shared
     * one, where it lets one organisation's spending exhaust another's limit
     * (#765). It is stated here as well as on `capPence()` because this is the
     * figure the admin page shows as "This month spend", which a customer will
     * read as their own.
     */
    public static function monthSpendPence(): int
    {
        $db = App::db();
        // 🏢 THIS ORGANISATION'S SPENDING, NOT THE INSTALLATION'S (#765).
        //    The budget this is compared against can differ by organisation —
        //    `SettingLimit` lets one hold itself to less than the installation
        //    allows, and the two administrators may be different parties
        //    (D-013). Summing across the installation meant one organisation's
        //    spending counted against every organisation's budget.
        //
        //    A row with NO organisation is counted by nobody, deliberately: it
        //    is a translation whose source has since been deleted, and
        //    attributing it would move real money between two parties' budgets
        //    on a guess. See migration 250.
        $siteId = Site::id();
        $stmt = $db->prepare(
            'SELECT COALESCE(SUM(`costPence`), 0) FROM `tblContentTranslation` '
            . 'WHERE `siteID` = ? AND `translatedAt` >= DATE_FORMAT(NOW(), "%Y-%m-01")'
        );
        if ($stmt === false) {
            return 0;
        }
        // ⚠️ BIND IT. The query gained a `?` when the sum was scoped to this
        //    organisation, and a prepared statement executed without its
        //    parameter throws — which would have turned a budget check into a
        //    fatal on the one page that spends money.
        $stmt->bind_param('i', $siteId);
        $stmt->execute();
        $stmt->bind_result($s);
        $stmt->fetch();
        $stmt->close();
        return (int) $s;
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
        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'x-api-key: ' . $key,
            'anthropic-version: 2023-06-01',
            'content-type: application/json',
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        // No curl_close() here: the handle has been an object since PHP 8.0 and is
        // freed when it goes out of scope. The call has done nothing since then,
        // and PHP 8.5 raises a "deprecated" notice every time it runs (#673).
        if ($resp === false || $code < 200 || $code >= 300) {
            return null;
        }
        $body = json_decode((string) $resp, true);
        if (is_array($body) === false || isset($body['content'][0]['text']) === false) {
            return null;
        }
        $out = trim((string) $body['content'][0]['text']);
        $inTok  = (int) ($body['usage']['input_tokens']  ?? 0);
        $outTok = (int) ($body['usage']['output_tokens'] ?? 0);
        // Haiku 4.5 list price ~$0.80 / $4 per MTok → tiny per call.
        $costPence = (int) ceil((($inTok * 0.00008) + ($outTok * 0.0004)) * 100);
        return ['text' => $out, 'costPence' => max(0, $costPence)];
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
        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $key,
            'Content-Type: application/json',
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($resp === false || $code < 200 || $code >= 300) {
            return null;
        }
        $body = json_decode((string) $resp, true);
        $out = is_array($body) === true ? trim((string) ($body['choices'][0]['message']['content'] ?? '')) : '';
        if ($out === '') {
            return null;
        }
        $inTok  = (int) ($body['usage']['prompt_tokens']     ?? 0);
        $outTok = (int) ($body['usage']['completion_tokens'] ?? 0);
        $costPence = (int) ceil((($inTok * 0.00015) + ($outTok * 0.0006)) * 100);
        return ['text' => $out, 'costPence' => max(0, $costPence)];
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
        $url = 'https://translation.googleapis.com/language/translate/v2?key=' . rawurlencode($key);
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'q'      => $text,
            'source' => $from,
            'target' => $to,
            'format' => 'text',
        ]));
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($resp === false || $code < 200 || $code >= 300) {
            return null;
        }
        $body = json_decode((string) $resp, true);
        $out = is_array($body) === true ? (string) ($body['data']['translations'][0]['translatedText'] ?? '') : '';
        if ($out === '') {
            return null;
        }
        // Google charges per source char; ~$20/M → ~1.5p / 10k chars.
        $cost = (int) ceil((mb_strlen($text) / 10000) * 1.5);
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
        $host = str_ends_with($key, ':fx') === true ? 'api-free.deepl.com' : 'api.deepl.com';
        $ch = curl_init('https://' . $host . '/v2/translate');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'text'        => $text,
            'source_lang' => strtoupper($from),
            'target_lang' => strtoupper($to),
        ]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: DeepL-Auth-Key ' . $key]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($resp === false || $code < 200 || $code >= 300) {
            return null;
        }
        $body = json_decode((string) $resp, true);
        $out = is_array($body) === true ? (string) ($body['translations'][0]['text'] ?? '') : '';
        if ($out === '') {
            return null;
        }
        $cost = (int) ceil((mb_strlen($text) / 10000) * 1.5);
        return ['text' => $out, 'costPence' => $cost];
    }

    /**
     * LibreTranslate (self-hosted or libretranslate.com).
     *
     * @link https://github.com/LibreTranslate/LibreTranslate
     */
    private static function libreTranslate(array $settings, string $text, string $from, string $to): ?array
    {
        $base = rtrim((string) ($settings['libre']['baseUrl'] ?? 'https://libretranslate.com'), '/');
        $key  = (string) ($settings['libre']['apiKey'] ?? '');
        $payload = ['q' => $text, 'source' => $from, 'target' => $to, 'format' => 'text'];
        if ($key !== '') {
            $payload['api_key'] = $key;
        }
        $ch = curl_init($base . '/translate');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($resp === false || $code < 200 || $code >= 300) {
            return null;
        }
        $body = json_decode((string) $resp, true);
        $out = is_array($body) === true ? (string) ($body['translatedText'] ?? '') : '';
        if ($out === '') {
            return null;
        }
        return ['text' => $out, 'costPence' => 0];
    }

    // -------------------------------------------------------------------------
    // Cache + helpers
    // -------------------------------------------------------------------------

    private static function lookupCached(string $table, int $id, string $field, string $target): ?string
    {
        $db = App::db();
        $stmt = $db->prepare(
            'SELECT translatedContent FROM tblContentTranslation '
            . 'WHERE sourceTable = ? AND sourceID = ? AND sourceField = ? AND targetLanguage = ? LIMIT 1'
        );
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('siss', $table, $id, $field, $target);
        $stmt->execute();
        $stmt->bind_result($content);
        $hit = $stmt->fetch() === true;
        $stmt->close();
        return $hit === true ? (string) $content : null;
    }

    private static function persist(string $table, int $id, string $field, string $source, string $target, string $content, string $provider, int $cost): void
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
        $siteId = Site::id();
        $stmt = $db->prepare(
            'INSERT INTO `tblContentTranslation` (`sourceTable`, `sourceID`, `sourceField`, '
            . '`sourceLanguage`, `targetLanguage`, `translatedContent`, `provider`, `costPence`, '
            . '`siteID`) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE `translatedContent` = VALUES(`translatedContent`), '
            . '    `provider` = VALUES(`provider`), `costPence` = VALUES(`costPence`), '
            . '    `siteID` = VALUES(`siteID`), `translatedAt` = NOW()'
        );
        if ($stmt === false) {
            return;
        }
        $stmt->bind_param('sissssisi', $table, $id, $field, $source, $target, $content, $provider, $cost, $siteId);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Normalise a locale (en-GB → en, EN → en, cy-cy → cy).
     */
    public static function normaliseLocale(string $locale): string
    {
        $bare = strtolower(trim($locale));
        if (strpos($bare, '-') !== false) {
            $bare = substr($bare, 0, strpos($bare, '-'));
        }
        return $bare === '' ? 'en' : $bare;
    }
}
