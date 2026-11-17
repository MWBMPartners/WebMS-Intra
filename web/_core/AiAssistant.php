<?php
// Path: _core/AiAssistant.php
/**
 * -----------------------------------------------------------------------------
 * AI-assisted content drafting + provider abstraction ✨
 * -----------------------------------------------------------------------------
 * Polish user-authored content (announcements, prayer requests, newsletter
 * blurbs) via a pluggable LLM:
 *
 *   ai_assist.provider = anthropic | openai | local
 *
 * Prompt templates live in tblAiPrompt — editable per kind/site so each org
 * can tune the assistant's tone. Defaults seed from
 * AiAssistant::defaultPrompts() the first time admin loads /admin/ai-assist.
 *
 * Guard rails: monthly cap (ai_assist.monthCapPence) and per-user daily cap
 * (ai_assist.userDailyCap) both checked before any API call; every call is
 * recorded in tblAiUsage with token counts + cost for the audit trail.
 *
 * THE MONEY (#993; D-173, D-192, D-194). A call's cost is worked out by
 * `ProviderSpend::cost()` in the currency the company charges in, added to
 * `tblProviderSpend` (which an erasure does not touch), and the monthly cap is
 * checked by `ProviderSpend::whyRefused()`: the installation's figure in the
 * installation's currency AND an organisation's own row in its own, the
 * stricter winning. Until #993 the cap was read from the MERGED settings, so
 * the installation did not cap an organisation (D-013), and the month's spend
 * was a sum over `tblAiUsage`, which an erasure lowered.
 *
 * @package   Portal\Core
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/277
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

class AiAssistant
{
    public const KINDS = [
        'announcement',
        'prayer-rewrite',
        'newsletter-blurb',
        'project-update',
    ];

    /**
     * Why the last `improve()` in this request answered null, as one word, so
     * the endpoint can say WHICH limit stopped it without working it out again
     * (the engine knows; a fresh read cannot tell "the provider failed" from
     * "the limit was already reached" — #762's rule).
     *
     *   ''            nothing refused (or improve() has not run)
     *   'bad-request' an unknown kind or empty text
     *   'off'         AI assist is switched off
     *   'money'       the month's money limit is reached
     *   'no-budget'   the monthly limit is set to nothing: nothing may be spent
     *   'no-rate'     a limit has to be checked and there is no exchange rate
     *                 for the company's currency (D-192)
     *   'no-currency' a limit has to be checked and no currency has been chosen
     *                 for the level that set it
     *   'unreadable'  this month's spend could not be read: nothing was sent
     *   'daily'       this person's daily limit is reached
     *   'provider'    the company was asked and did not give a usable answer
     */
    private static string $lastRefusal = '';

    /** Why the last `improve()` in this request answered null. See the property. */
    public static function lastRefusal(): string
    {
        return self::$lastRefusal;
    }

    /**
     * Improve a chunk of user text under the given prompt kind. Returns
     * the polished string, or null when blocked by cap / rate-limit /
     * provider failure (caller decides what to surface to the user).
     */
    public static function improve(int $siteId, int $userId, string $kind, string $userInput): ?string
    {
        self::$lastRefusal = '';
        if (in_array($kind, self::KINDS, true) === false) {
            self::$lastRefusal = 'bad-request';
            return null;
        }
        $userInput = trim($userInput);
        if ($userInput === '') {
            self::$lastRefusal = 'bad-request';
            return null;
        }

        $settings = App::settings()['ai_assist'] ?? [];
        if ((string) ($settings['enabled'] ?? '0') !== '1') {
            self::$lastRefusal = 'off';
            return null;
        }
        $provider = (string) ($settings['provider'] ?? 'anthropic');
        // 💰 THE MONEY LIMIT, from the database at each level and never from
        //    the merged settings (D-013, D-194): see the class header. A free
        //    local route is still checked against what was already spent, as
        //    before, and a limit of 0 means nothing may be spent.
        $whyMoney = ProviderSpend::whyRefusedFor(App::db(), $siteId, 'ai', $provider);
        if ($whyMoney !== '') {
            self::$lastRefusal = match ($whyMoney) {
                'installation-limit', 'organisation-limit' => 'money',
                'no-budget'                                => 'no-budget',
                'no-rate'                                  => 'no-rate',
                'no-currency'                              => 'no-currency',
                default                                    => 'unreadable',
            };
            return null;
        }
        if (self::userDailyCount($siteId, $userId) >= (int) ($settings['userDailyCap'] ?? 20)) {
            self::$lastRefusal = 'daily';
            return null;
        }

        $template = self::activeTemplate($siteId, $kind);
        $audience = (string) ($settings['audience'] ?? 'congregation');
        $orgType  = (string) (App::settings()['portal']['industry'] ?? 'church');
        $prompt   = strtr($template, [
            '{user_input}' => $userInput,
            '{audience}'   => $audience,
            '{org_type}'   => $orgType,
        ]);

        $result = self::providerCall($provider, $settings, $prompt);
        if ($result === null) {
            self::$lastRefusal = 'provider';
            return null;
        }

        self::recordUsage(
            $siteId,
            $userId,
            $kind,
            $provider,
            (int) $result['inputTokens'],
            (int) $result['outputTokens'],
            $result['cost'],
            $userInput,
            (string) $result['text']
        );
        return (string) $result['text'];
    }

    /**
     * Resolve the active prompt template for a kind. Falls back to the
     * site default seed when no row exists yet.
     */
    public static function activeTemplate(int $siteId, string $kind): string
    {
        $db = App::db();
        $stmt = $db->prepare('SELECT promptTemplate FROM tblAiPrompt WHERE siteID = ? AND kind = ? AND isActive = 1 LIMIT 1');
        if ($stmt !== false) {
            $stmt->bind_param('is', $siteId, $kind);
            $stmt->execute();
            $stmt->bind_result($tpl);
            $hit = $stmt->fetch() === true;
            $stmt->close();
            if ($hit === true) {
                return (string) $tpl;
            }
        }
        return self::defaultPrompts()[$kind] ?? self::defaultPrompts()['announcement'];
    }

    /**
     * Default prompt seeds — used until an admin overrides them. Use
     * {user_input}, {audience}, {org_type} placeholders.
     */
    public static function defaultPrompts(): array
    {
        return [
            'announcement' =>
                "You are helping a {org_type} volunteer polish a draft announcement.\n"
                . "Tone: warm, clear, suitable for a {audience}. Keep it brief — under 100 words.\n"
                . "Preserve all factual details (dates, times, names). Don't add information that isn't in the original.\n"
                . "Reply with ONLY the polished version — no preamble, no quotation marks.\n\n"
                . "Original draft:\n{user_input}\n\nPolished version:",
            'prayer-rewrite' =>
                "You are helping a {org_type} member share a prayer request with their {audience}.\n"
                . "Make it warm and respectful. Keep names anonymous if the original doesn't include them.\n"
                . "Don't add theological commentary. Preserve the specific need described.\n"
                . "Reply with ONLY the polished version.\n\nOriginal:\n{user_input}\n\nPolished:",
            'newsletter-blurb' =>
                "You are helping a {org_type} comms lead write a short newsletter blurb for a {audience}.\n"
                . "Length: 60-90 words. Friendly, scannable, ends with a clear action if appropriate.\n"
                . "Preserve every date, time, name, and link from the original.\n"
                . "Reply with ONLY the polished version.\n\nOriginal notes:\n{user_input}\n\nBlurb:",
            'project-update' =>
                "You are helping a {org_type} project lead post an update on their fundraising page.\n"
                . "Tone: grateful, transparent, concrete. Cite progress and what's next.\n"
                . "Length: 80-120 words. Preserve numbers and dates exactly.\n"
                . "Reply with ONLY the polished version.\n\nNotes:\n{user_input}\n\nUpdate:",
        ];
    }

    public static function userDailyCount(int $siteId, int $userId): int
    {
        $db = App::db();
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM tblAiUsage WHERE siteID = ? AND userID = ? AND DATE(occurredAt) = CURDATE()'
        );
        if ($stmt === false) {
            return 0;
        }
        $stmt->bind_param('ii', $siteId, $userId);
        $stmt->execute();
        $stmt->bind_result($n);
        $stmt->fetch();
        $stmt->close();
        return (int) $n;
    }

    public static function upsertPrompt(int $siteId, string $kind, string $template, bool $active): bool
    {
        $db = App::db();
        $activeInt = $active === true ? 1 : 0;
        $stmt = $db->prepare(
            'INSERT INTO tblAiPrompt (siteID, kind, promptTemplate, isActive) VALUES (?, ?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE promptTemplate = VALUES(promptTemplate), isActive = VALUES(isActive)'
        );
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param('issi', $siteId, $kind, $template, $activeInt);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    // -------------------------------------------------------------------------
    // Providers — each returns ['text','inputTokens','outputTokens','cost'] or
    //   null on failure; 'cost' is ProviderSpend::cost()'s answer (null: free).
    // -------------------------------------------------------------------------

    private static function providerCall(string $provider, array $settings, string $prompt): ?array
    {
        switch ($provider) {
            case 'anthropic':
                return self::anthropicCall($settings, $prompt);
            case 'openai':
                return self::openaiCall($settings, $prompt);
            case 'local':
                return self::localCall($settings, $prompt);
            default:
                return null;
        }
    }

    private static function anthropicCall(array $settings, string $prompt): ?array
    {
        $key   = (string) ($settings['anthropic']['apiKey'] ?? '');
        $model = (string) ($settings['anthropic']['model'] ?? 'claude-haiku-4-5-20251001');
        if ($key === '') {
            return null;
        }
        $payload = [
            'model'      => $model,
            'max_tokens' => 1024,
            'messages'   => [['role' => 'user', 'content' => $prompt]],
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
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
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
        $text = trim((string) $body['content'][0]['text']);
        $inTok  = (int) ($body['usage']['input_tokens']  ?? 0);
        $outTok = (int) ($body['usage']['output_tokens'] ?? 0);
        return ['text' => $text, 'inputTokens' => $inTok, 'outputTokens' => $outTok,
                'cost' => ProviderSpend::cost('ai', 'anthropic', $model, ['in' => $inTok, 'out' => $outTok])];
    }

    private static function openaiCall(array $settings, string $prompt): ?array
    {
        $key   = (string) ($settings['openai']['apiKey'] ?? '');
        $model = (string) ($settings['openai']['model'] ?? 'gpt-4o-mini');
        if ($key === '') {
            return null;
        }
        $payload = [
            'model'       => $model,
            'messages'    => [['role' => 'user', 'content' => $prompt]],
            'temperature' => 0.4,
        ];
        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $key,
            'Content-Type: application/json',
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($resp === false || $code < 200 || $code >= 300) {
            return null;
        }
        $body = json_decode((string) $resp, true);
        $text = is_array($body) === true ? trim((string) ($body['choices'][0]['message']['content'] ?? '')) : '';
        if ($text === '') {
            return null;
        }
        $inTok  = (int) ($body['usage']['prompt_tokens']     ?? 0);
        $outTok = (int) ($body['usage']['completion_tokens'] ?? 0);
        return ['text' => $text, 'inputTokens' => $inTok, 'outputTokens' => $outTok,
                'cost' => ProviderSpend::cost('ai', 'openai', $model, ['in' => $inTok, 'out' => $outTok])];
    }

    /**
     * Local ollama-compatible endpoint. Free; assumes ai_assist.local.baseUrl
     * runs an OpenAI-compatible /v1/chat/completions (ollama serves that
     * shape from v0.4+).
     */
    private static function localCall(array $settings, string $prompt): ?array
    {
        $base  = rtrim((string) ($settings['local']['baseUrl'] ?? 'http://localhost:11434'), '/');
        $model = (string) ($settings['local']['model'] ?? 'llama3.2');
        if ($base === '') {
            return null;
        }
        $payload = [
            'model'    => $model,
            'messages' => [['role' => 'user', 'content' => $prompt]],
        ];
        $ch = curl_init($base . '/v1/chat/completions');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($resp === false || $code < 200 || $code >= 300) {
            return null;
        }
        $body = json_decode((string) $resp, true);
        $text = is_array($body) === true ? trim((string) ($body['choices'][0]['message']['content'] ?? '')) : '';
        if ($text === '') {
            return null;
        }
        return ['text' => $text, 'inputTokens' => 0, 'outputTokens' => 0, 'cost' => null];
    }

    /**
     * @param array{currency:string, millionths:int}|null $cost
     */
    private static function recordUsage(int $siteId, int $userId, string $kind, string $provider, int $inTok, int $outTok, ?array $cost, string $inputSample, string $outputSample): void
    {
        $db = App::db();
        // The month's money: the row below is the audit trail (an erasure
        // deletes it), this is what the limit reads (an erasure does not).
        ProviderSpend::record($db, $siteId, 'ai', $cost);
        $costCurrency   = $cost === null ? null : (string) $cost['currency'];
        $costMillionths = $cost === null ? null : (int) $cost['millionths'];
        $inSnip  = mb_substr($inputSample, 0, 1000);
        $outSnip = mb_substr($outputSample, 0, 1000);
        $stmt = $db->prepare(
            'INSERT INTO `tblAiUsage` (`siteID`, `userID`, `promptKind`, `provider`, `inputTokens`, `outputTokens`, `costCurrency`, `costMillionths`, `inputSample`, `outputSample`) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        if ($stmt === false) {
            return;
        }
        $stmt->bind_param('iissiisiss', $siteId, $userId, $kind, $provider, $inTok, $outTok, $costCurrency, $costMillionths, $inSnip, $outSnip);
        $stmt->execute();
        $stmt->close();
    }
}
