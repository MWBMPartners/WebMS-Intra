<?php
// Path: _core/LiveChat.php
/**
 * -----------------------------------------------------------------------------
 * Portal Core LiveChat 💬
 * -----------------------------------------------------------------------------
 * Reusable primitives for the COP Online Engagement live chat (#313 Phase 1).
 *
 * Why this exists: the chat endpoints (api/live-chat-send, list, moderate)
 * share validation, profanity filtering, and sliding-window rate-limit logic.
 * Centralising it here keeps each route handler small and lets Phase 2
 * (reactions, host push) reuse the same primitives.
 *
 * Public methods:
 *   LiveChat::isValidSessionToken($token)         → bool
 *   LiveChat::profanityList()                     → array<string>
 *   LiveChat::containsProfanity($body)            → bool
 *   LiveChat::maskProfanity($body)                → string (asterisks)
 *   LiveChat::isRateLimited($mysqli, $token, $ip) → bool
 *   LiveChat::recordSend($mysqli, $siteId, $token, $ip) → void
 *   LiveChat::clientIp()                          → string
 *   LiveChat::normaliseDisplayName($raw)          → string
 *   LiveChat::maxBodyChars()                      → int
 *   LiveChat::autoApprove()                       → bool
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/313
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

use mysqli;

class LiveChat
{
    /**
     * 🔑 Session-token format check.
     *
     * Mirrors api/livestream-ping.php regex (livestream-sessions-schema-recon)
     * so tokens minted by the embed pinger validate identically here.
     *
     * @param string $token Candidate token (32-64 lowercase hex chars)
     */
    public static function isValidSessionToken(string $token): bool
    {
        return preg_match('/^[a-f0-9]{32,64}$/', $token) === 1;
    }

    /**
     * 🗂️ Profanity list — admin-editable CSV in setting chat.profanityList.
     *
     * @return array<int, string> Lower-cased, trimmed, de-duplicated word list
     */
    public static function profanityList(): array
    {
        $csv = (string) Settings::get('chat.profanityList', '');
        if ($csv === '') {
            return [];
        }
        $words = array_filter(
            array_map(
                static fn(string $w): string => strtolower(trim($w)),
                explode(',', $csv)
            ),
            static fn(string $w): bool => $w !== ''
        );
        return array_values(array_unique($words));
    }

    /**
     * 🚩 Returns true if the body contains any banned word (case-insensitive
     * whole-word match; punctuation/whitespace boundaries).
     */
    public static function containsProfanity(string $body): bool
    {
        $words = self::profanityList();
        if (count($words) === 0) {
            return false;
        }
        $haystack = strtolower($body);
        foreach ($words as $word) {
            // 🔍 Word-boundary match — avoids false positives on substrings
            //    (e.g. 'hello' will not match the banned 'hell').
            $pattern = '/\b' . preg_quote($word, '/') . '\b/u';
            if (preg_match($pattern, $haystack) === 1) {
                return true;
            }
        }
        return false;
    }

    /**
     * 🔒 Replace banned tokens with asterisks of equal length so flagged
     * messages remain visible to moderators with context but harmless
     * if accidentally surfaced.
     */
    public static function maskProfanity(string $body): string
    {
        $words = self::profanityList();
        if (count($words) === 0) {
            return $body;
        }
        $masked = $body;
        foreach ($words as $word) {
            $pattern    = '/\b' . preg_quote($word, '/') . '\b/iu';
            $replacement = str_repeat('*', mb_strlen($word));
            $result = preg_replace($pattern, $replacement, $masked);
            if (is_string($result) === true) {
                $masked = $result;
            }
        }
        return $masked;
    }

    /**
     * 🎯 Sliding-window throttle check.
     *
     * Returns true if the sessionToken (or IP) has sent more than
     * chat.maxMsgsPerWindow messages in the last chat.windowSeconds seconds.
     * Uses tblLiveRateLimits because RateLimiter is login-only
     * (ratelimiter-captcha-public-post-recon).
     *
     * @param mysqli $mysqli Database handle (injected from caller)
     * @param string $token  Session token (already validated)
     * @param string $ip     Client IP (CF-Connecting-IP honoured)
     */
    public static function isRateLimited(mysqli $mysqli, string $token, string $ip): bool
    {
        $maxMsgs    = (int) Settings::get('chat.maxMsgsPerWindow', '5');
        $windowSecs = (int) Settings::get('chat.windowSeconds', '60');
        if ($maxMsgs <= 0 || $windowSecs <= 0) {
            return false;
        }

        // 🕒 Count by token first (primary key) — token is per-session, so
        //    this catches the same user spamming through one session.
        $sql  = 'SELECT COUNT(*) AS c FROM tblLiveRateLimits '
              . 'WHERE sessionToken = ? AND eventType = ? '
              . 'AND createdAt >= DATE_SUB(NOW(), INTERVAL ? SECOND)';
        $stmt = $mysqli->prepare($sql);
        if ($stmt === false) {
            \Portal\Core\Logger::errorPlatform('LiveChat', 'Warning', 'rate-limit-prepare-failed', 'isRateLimited prepare failed; failing closed', '');
            return true;
        }
        $eventType = 'chat.send';
        $stmt->bind_param('ssi', $token, $eventType, $windowSecs);
        $stmt->execute();
        $countByToken = (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0);
        $stmt->close();
        if ($countByToken >= $maxMsgs) {
            return true;
        }

        // 🕒 Also count by IP — catches the same client cycling tokens
        //    (open new tab → new token → spam again).
        if ($ip !== '' && $ip !== '0.0.0.0') {
            $ipMax  = $maxMsgs * 3; // looser IP cap accommodates shared NAT
            $stmt   = $mysqli->prepare(
                'SELECT COUNT(*) AS c FROM tblLiveRateLimits '
                . 'WHERE senderIP = ? AND eventType = ? '
                . 'AND createdAt >= DATE_SUB(NOW(), INTERVAL ? SECOND)'
            );
            if ($stmt === false) {
                \Portal\Core\Logger::errorPlatform('LiveChat', 'Warning', 'rate-limit-prepare-failed', 'isRateLimited IP-query prepare failed; failing closed', '');
                return true;
            }
            $stmt->bind_param('ssi', $ip, $eventType, $windowSecs);
            $stmt->execute();
            $countByIp = (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0);
            $stmt->close();
            if ($countByIp >= $ipMax) {
                return true;
            }
        }

        return false;
    }

    /**
     * 📝 Record a successful send into the throttle log.
     *
     * Idempotency: each row represents ONE send event; pruning is done
     * incidentally (next call rolls forward via the time window).
     */
    public static function recordSend(mysqli $mysqli, int $siteId, string $token, string $ip): void
    {
        $stmt = $mysqli->prepare(
            'INSERT INTO tblLiveRateLimits (siteID, sessionToken, senderIP, eventType) '
            . 'VALUES (?, ?, ?, ?)'
        );
        if ($stmt === false) {
            return;
        }
        $ipArg     = $ip !== '' ? $ip : null;
        $eventType = 'chat.send';
        $stmt->bind_param('isss', $siteId, $token, $ipArg, $eventType);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * 🌐 Client IP honouring CloudFlare → X-Forwarded-For → REMOTE_ADDR.
     * Mirrors RateLimiter::getClientIp pattern for consistency.
     */
    public static function clientIp(): string
    {
        if (isset($_SERVER['HTTP_CF_CONNECTING_IP']) === true) {
            $ip = (string) $_SERVER['HTTP_CF_CONNECTING_IP'];
            return mb_substr($ip, 0, 45);
        }
        if (isset($_SERVER['HTTP_X_FORWARDED_FOR']) === true) {
            $parts = explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR']);
            $ip    = trim($parts[0] ?? '');
            return mb_substr($ip, 0, 45);
        }
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        return mb_substr($ip, 0, 45);
    }

    /**
     * 📛 Trim + collapse whitespace + cap length on the display name.
     * Empty input falls back to 'Guest'.
     */
    public static function normaliseDisplayName(string $raw): string
    {
        $stripped = preg_replace('/\s+/u', ' ', trim($raw)) ?? '';
        if ($stripped === '') {
            return 'Guest';
        }
        return mb_substr($stripped, 0, 60);
    }

    /**
     * 📏 Max body length (admin-tunable; default 500 chars).
     */
    public static function maxBodyChars(): int
    {
        $cfg = (int) Settings::get('chat.maxBodyChars', '500');
        if ($cfg <= 0) {
            return 500;
        }
        // 🔒 Hard cap matches schema's body VARCHAR(500); never let admin
        //    push past the column width without a schema change.
        return min($cfg, 500);
    }

    /**
     * 🎫 Auto-approve mode — if true, new messages skip the pending
     * queue and land as 'approved'. Default false (pre-moderation).
     */
    public static function autoApprove(): bool
    {
        $raw = (string) Settings::get('chat.autoApprove', 'false');
        return $raw === 'true' || $raw === '1';
    }
}
