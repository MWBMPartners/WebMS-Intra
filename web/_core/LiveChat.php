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
 *   LiveChat::eventOpenToViewer($db, $siteId, $eventId, $ownPagesOnly) → bool
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
     * 👁️ May whoever is asking see this event's live chat and livestream?
     *
     * THE ONE RULE for the chat list, the prompts, sending a message, the
     * viewer ping and the chat box on `/live` (12 October 2026). It is who
     * may see the EVENT, worked out exactly as the events detail endpoint
     * does (events/api/detail.php): the event belongs to this organisation,
     * is not deleted and not imported, is published, cancelled or postponed
     * (or a draft, for somebody who can manage events), and the shared rule
     * EventVisibility says this viewer may see it.
     *
     * So:
     *   - a PUBLIC event is open to everybody, signed in or not;
     *   - an INTERNAL event is open only to a signed-in member or
     *     administrator of this organisation, and only inside the portal
     *     (the owner's decision, 12 October 2026).
     *
     * "ONLY INSIDE THE PORTAL" IS THE FOURTH ARGUMENT. The four data
     * endpoints (chat list, prompts, send, viewer ping) pass
     * `$ownPagesOnly = true`. The sign-in then counts only when the browser
     * itself says the request was made by one of the portal's own pages
     * (requestCameFromOwnPages() below). A request from anywhere else is
     * treated as coming from nobody: a public event still answers, an
     * internal one does not. The `/live` page passes false, because it is a
     * page somebody opens (from a bookmark, a link in an email), not a
     * request another page makes; it only decides whether to draw the box,
     * and it writes nothing.
     *
     * The argument has NO default on purpose: a caller that forgets it gets
     * an error, not an open door.
     *
     * WHAT WAS WRONG, twice:
     *   - Until 12 October 2026 the four endpoints tested the organisation
     *     and nothing else. Anybody, signed in or not, could read an internal
     *     event's approved messages and prompts by its number, start a viewer
     *     session for it and then post into its chat (#548). And whether the
     *     box appeared on `/live` told any signed-in account, of any
     *     organisation, which internal event numbers exist.
     *   - The first fix rested on the sign-in cookie alone. A browser sends
     *     that cookie to the portal from any page on the same registered
     *     name (the organisation's own website on "www", say), and these
     *     endpoints ask for no form token. So a page there could post into
     *     an internal event's chat through a signed-in member who merely
     *     opened it. Found by the independent check, in a real browser.
     *
     * The rights are decided BEFORE the look-up and bound into it as a
     * yes/no value, so a refused event, a deleted one and a made-up number
     * all run this one statement and all come back "no". Callers must give
     * a refused event the same answer they give a made-up number.
     *
     * WHAT THIS CANNOT DO:
     *   - It cannot tell whether a livestream is set up for the event: no
     *     column ties an event to a stream (channels belong to the
     *     organisation). It answers "may see the event", nothing narrower.
     *   - It reads who is signed in from the session. A request that
     *     carries no session cookie is "nobody", whoever sent it. The chat
     *     box script therefore has to send the cookie when it runs on the
     *     portal's own pages (web/public_html/assets/js/livechat-widget.js).
     *   - It does not make the chat box work on another website. That
     *     script asks the address of the page it is on, never the portal,
     *     so on a page served from anywhere else no chat is answered at
     *     all, public event or not.
     *   - An API key is not looked at: none of the callers accepts one.
     *
     * @param mysqli $db           An open connection.
     * @param int    $siteId       The organisation whose address was asked.
     * @param int    $eventId      The event number that was sent.
     * @param bool   $ownPagesOnly True for a data endpoint: count the sign-in
     *                             only for a request made by the portal's own
     *                             pages. False only for a page a person opens.
     *
     * @return bool True only when this viewer may see that event.
     */
    public static function eventOpenToViewer(mysqli $db, int $siteId, int $eventId, bool $ownPagesOnly): bool
    {
        if ($siteId <= 0 || $eventId <= 0) {
            return false;
        }

        // Whose sign-in counts. Decided once, here, so the two values below
        // cannot disagree: a request that did not come from the portal's own
        // pages is nobody, however good its cookie.
        $signInCounts = $ownPagesOnly === false || self::requestCameFromOwnPages() === true;

        // App::isAdmin() is false for nobody: it answers from the signed-in
        // account's own row, and there is none.
        $canManageFlag = ($signInCounts === true && App::isAdmin() === true) ? 1 : 0;
        // "session" mode with viewer 0 is the documented "nobody" case
        // (EventVisibility): public events only.
        $viewerId   = $signInCounts === true ? EventVisibility::sessionViewerId() : 0;
        $visibility = EventVisibility::where('e', EventVisibility::MODE_SESSION, $viewerId, date('Y-m-d'));

        $stmt = $db->prepare(
            'SELECT 1 FROM tblEvents e WHERE e.eventID = ? AND e.siteID = ? AND e.isDeleted = 0 AND e.externalFeedID IS NULL '
            . "AND (e.status IN ('published', 'cancelled', 'postponed') OR ? = 1)" . $visibility['sql'] . ' LIMIT 1'
        );
        if ($stmt === false) {
            // Could not ask: say no. A chat that is closed for a moment is
            // the safe mistake; one that is open to everybody is not.
            return false;
        }
        $stmt->bind_param('iii' . $visibility['types'], $eventId, $siteId, $canManageFlag, ...$visibility['params']);
        $stmt->execute();
        $open = $stmt->get_result()->fetch_row() !== null;
        $stmt->close();

        return $open;
    }

    /**
     * 🧭 Was this request made by one of the portal's own pages?
     *
     * Asked only by eventOpenToViewer(), for the chat's data endpoints. The
     * answer is yes ONLY when the browser itself says so. Anything missing,
     * odd or unreadable is a no: a chat that stays empty is the safe
     * mistake.
     *
     * It reads three headers, in this order, and the first one that was
     * sent decides. The browser fills them in. A page's own script cannot
     * write `Sec-Fetch-Site` at all, and cannot make `Origin` or `Referer`
     * name any scheme, name and port but its own page's. The most it can do
     * is have `Origin` say `null`, and leave `Referer` out or shorten it;
     * each of those is a no below.
     *
     *   1. `Sec-Fetch-Site`: must be exactly `same-origin`, meaning the page
     *      that asked has the same scheme, name and port as the address
     *      asked. `same-site` is another address under the same registered
     *      name (the hole this closes); `cross-site` is another website;
     *      `none` is somebody typing the address, which is not a page of
     *      ours either. All three are a no, and so is any other value.
     *      Nothing is compared with a name here, so this holds whichever
     *      address the portal is reached at, and however organisations are
     *      told apart.
     *      WHO SENDS IT: every current browser, with GET and POST alike and
     *      whatever kind of body is sent, but ONLY when the address asked
     *      is an https one (or is the browser's own machine, `localhost`
     *      or `127.0.0.1`). Over plain http no browser sends it, so on a
     *      portal reached that way steps 2 and 3 decide for everybody, not
     *      only for older browsers. (An earlier version of this comment
     *      said "every current browser, on every request". A current
     *      browser asked a plain http portal in the independent check of
     *      12 October 2026 and sent none.)
     *   2. When that header was not sent: `Origin`. A browser sends it with
     *      a POST (some older ones leave it off a POST to their own
     *      address), and with a request to another address that asks to
     *      read the answer. Its scheme, name and port must be the ones THIS
     *      request was sent to.
     *   3. With neither: `Referer`, the address of the page that asked,
     *      judged the same way. This is what decides a GET to the page's
     *      own address, which is how the chat list and the prompts are
     *      fetched, and a request from another address that does not ask to
     *      read the answer (an image or a script tag). The portal's own
     *      pages ask browsers to send it to the portal itself
     *      (bootstrap.php, Referrer-Policy).
     *   4. None of the three: no.
     *
     * The address compared against is the one this very request named
     * (its `Host` header). No name is written into the code or read from a
     * setting, so an installation reached at several addresses needs
     * nothing set up: a page counts only for the address it was served
     * from.
     *
     * WHAT THIS CAN STOP: a page that is not the portal's, on another
     * website or on another address of the same site, using a signed-in
     * member's browser to read, join or post in an internal event's chat.
     *
     * WHAT IT CANNOT STOP, OR DO:
     *   - A script running on one of the portal's own pages is "own pages".
     *     When organisations are told apart by the first part of the
     *     address they all share one address, so every organisation's
     *     portal pages count; who may see the event is still decided by
     *     membership, in eventOpenToViewer().
     *   - A program that is not a browser can write any header it likes.
     *     It still needs the person's cookie, so it is that person, or
     *     somebody who already holds their session.
     *   - A browser that sends no `Sec-Fetch-Site` (an older one, or ANY
     *     browser when the portal is reached over plain http), on a page
     *     that sends no `Referer` (the organisation set
     *     `portal.headers.referrer_policy` to `no-referrer`, or a privacy
     *     tool strips it), is refused for the chat list and the prompts: a
     *     member there sees an empty chat.
     *   - Steps 2 and 3 need this request's own scheme. Behind a proxy
     *     that ends https and does not say so (`X-Forwarded-Proto`), this
     *     request looks like plain http while the member's page is https,
     *     so an older browser is refused. A browser that sends
     *     `Sec-Fetch-Site` is not affected, as long as the proxy passes
     *     that header on: step 1 compares nothing.
     *   - In that same set-up the rule FAILS OPEN in one narrow case: an
     *     older browser on a page served at the portal's own name over
     *     plain `http://` is accepted, because that page and this request
     *     both look like plain http. It adds no new exposure. The only
     *     pages at that address are the portal's own, or those of somebody
     *     who can get in between the member and the portal; and in that
     *     set-up the sign-in cookie is not marked "secure" either
     *     (Auth::ensureSession() goes by `$_SERVER['HTTPS']`, which such a
     *     proxy leaves unset), so that person could take the cookie itself.
     *
     * @return bool True only when the browser says the page that asked was
     *              served from the address that was asked.
     */
    private static function requestCameFromOwnPages(): bool
    {
        // 1. The browser's own verdict. isset(), not "is it empty": a header
        //    that was sent with nothing in it is still not "same-origin".
        if (isset($_SERVER['HTTP_SEC_FETCH_SITE']) === true) {
            return strtolower(trim((string) $_SERVER['HTTP_SEC_FETCH_SITE'])) === 'same-origin';
        }

        // The address this request was sent to. The scheme test is the one
        // bootstrap.php uses before sending the "https only" header.
        $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
        if ($host === '') {
            return false;
        }
        $https = (isset($_SERVER['HTTPS']) === true && $_SERVER['HTTPS'] !== '' && strtolower((string) $_SERVER['HTTPS']) !== 'off')
            || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
        $own = self::schemeNameAndPort(($https === true ? 'https' : 'http') . '://' . $host);
        if ($own === '') {
            return false;
        }

        // 2 and 3. Whichever was sent first decides; an `Origin` that does
        //    not match is never rescued by a `Referer` that does.
        foreach (['HTTP_ORIGIN', 'HTTP_REFERER'] as $header) {
            if (isset($_SERVER[$header]) === true) {
                return self::schemeNameAndPort(trim((string) $_SERVER[$header])) === $own;
            }
        }

        // 4. Nothing to go on.
        return false;
    }

    /**
     * 🔗 The "scheme://name:port" of an address, in one spelling: lower
     * case, and without a port that is the scheme's usual one (80 or 443),
     * because browsers leave those out.
     *
     * Returns '', which never equals a real address, for anything that is
     * not an http or https address (`Origin: null`), for one that carries a
     * user part (`name@`), and for one whose port is written as 0.
     *
     * The last two were added on 12 October 2026. This used to read past a
     * user part, so `https://elsewhere.example\@portal.example` came back
     * as the portal's own address, and it treated a port written as 0 as
     * "no port". No browser writes either into `Origin` or `Referer`, so
     * neither could be reached from a page; they are refused so that this
     * function does what it says.
     *
     * WHAT IT DOES NOT DO: tidy up two values sent in one header. Those
     * come back as one long name with a comma or a space in it, not as '',
     * and are a no only because no request is ever sent to such a name.
     */
    private static function schemeNameAndPort(string $address): string
    {
        $parts = parse_url($address);
        if (is_array($parts) === false) {
            return '';
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $name   = strtolower((string) ($parts['host'] ?? ''));
        if (($scheme !== 'http' && $scheme !== 'https') || $name === '') {
            return '';
        }
        // isset(), not "is it empty": `https://@portal.example` has a user
        // part with nothing in it, and is refused like any other.
        if (isset($parts['user']) === true || isset($parts['pass']) === true) {
            return '';
        }
        // null means no port was written, which is the scheme's usual one.
        $port = $parts['port'] ?? null;
        if ($port === 0) {
            return '';
        }
        if ($port === null || ($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443)) {
            return $scheme . '://' . $name;
        }

        return $scheme . '://' . $name . ':' . $port;
    }

    /**
     * 🔑 Session-token format check.
     *
     * The same pattern as the viewer ping (_apps/livestream/api/ping.php), so
     * a token made by the chat box or the embed snippet is accepted here too.
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
     * 🌐 The visitor's address, as decided by RateLimiter::clientIp() — the
     * one place in the portal that decides which address to believe.
     */
    public static function clientIp(): string
    {
        // 🛑 This used to read the Cloudflare and X-Forwarded-For headers
        //    directly and believe them. Anybody can send those headers, so
        //    anybody could claim any address they liked. RateLimiter::clientIp()
        //    is now the ONE place in the portal that decides which address to
        //    believe: it trusts those headers only when the request really
        //    arrived through a proxy listed in the portal.trustedProxies
        //    setting, and otherwise uses the address the connection actually
        //    came from. Delegating means a fix to that rule reaches this code
        //    too, instead of this copy quietly drifting out of step - which is
        //    exactly how it went wrong. It matters here because the chat's rate
        //    limit in livechat/api/send.php is keyed on this address. Kept as a
        //    method because other code calls LiveChat::clientIp(). The
        //    45-character clamp is kept: 45 is the longest an IPv6 address can
        //    be written, and it matches the column this is stored in.
        return mb_substr(RateLimiter::clientIp(), 0, 45);
    }

    /**
     * The longest name a chat message is stored under, in characters. It is
     * the width of the column it goes into, `tblLiveChatMessages.displayName`
     * (VARCHAR(40) in web/_sql/full_schema.sql). Change the two together.
     */
    private const DISPLAY_NAME_MAX = 40;

    /**
     * 📛 Trim + collapse whitespace + cap length on the display name.
     * Empty input falls back to 'Guest'.
     *
     * The cap used to be 60, twenty more than the column holds. The chat
     * box itself stops at 40, so nobody typing a name saw it; but a name of
     * more than 40 characters sent straight to the "send" endpoint was
     * refused by the database, and the sender got a bare 500 (found by the
     * independent check, 12 October 2026). A longer name is now cut to its
     * first 40 characters and the message is stored.
     */
    public static function normaliseDisplayName(string $raw): string
    {
        $stripped = preg_replace('/\s+/u', ' ', trim($raw)) ?? '';
        if ($stripped === '') {
            return 'Guest';
        }
        return mb_substr($stripped, 0, self::DISPLAY_NAME_MAX);
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
